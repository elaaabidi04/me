<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractEntryJob;
use App\Jobs\GenerateCoachMessageJob;
use App\Models\CoachMessage;
use App\Services\CoachService;
use App\Services\EntryExtractionService;
use App\Support\DemoSandbox;
use App\Support\InjectionGuard;
use App\Models\DailyReview;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()
            ->entries()
            ->with(['analysis', 'coachMessage'])
            ->latest();

        // Optional date filter for the calendar day view (YYYY-MM-DD)
        if ($date = $request->query('date')) {
            $query->whereDate('created_at', $date);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * GET /api/entries/{entry}
     * A single entry with its full analysis, any corrections already made
     * to it, and the entities extracted from it — the "verify accuracy"
     * inspector view's data source. Ownership is enforced the same way as
     * index(): scoped through $request->user()->entries(), never a bare
     * Entry::find(), so one user can't fetch another's journal by guessing
     * an id.
     */
    public function show(Request $request, $id)
    {
        $entry = $request->user()
            ->entries()
            ->with(['analysis.corrections', 'entities'])
            ->findOrFail($id);

        return response()->json($entry);
    }

    /**
     * GET /api/entries/dates?month=YYYY-MM
     * Returns a map of { "YYYY-MM-DD": count } for every day in the given
     * month that has at least one entry. Used by the calendar to render dots.
     */
    public function dates(Request $request)
    {
        $month = $request->query('month', now()->format('Y-m'));
        [$year, $mon] = explode('-', $month);

        $rows = $request->user()
            ->entries()
            ->whereYear('created_at', (int) $year)
            ->whereMonth('created_at', (int) $mon)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
            ->groupByRaw('DATE(created_at)')
            ->pluck('cnt', 'day');

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'body'            => ['required', 'string'],
            'daily_intent_id' => ['nullable', 'integer', 'exists:daily_intents,id'],
            'in_vault'        => ['boolean'],
        ]);

        // 2026-11-10: demo visitors get config('demo.entries_per_visitor')
        // entries (= AI replies) per demo copy. Crisis language is never
        // turned away, even with no tries left.
        $user = $request->user();
        if ($user->is_demo && ! DemoSandbox::unlimited($request)) {
            $isCrisis = app(CoachService::class)->isCrisisText($data['body']);
            if (! $isCrisis && DemoSandbox::triesLeft($user) <= 0) {
                return response()->json([
                    'message'    => "That's all ".config('demo.entries_per_visitor', 3)." demo entries used. Everything else (dashboard, threads, patterns, monthly) is still yours to explore.",
                    'tries_left' => 0,
                ], 429);
            }
            if (! $isCrisis) {
                DemoSandbox::useTry($user);
            }
        }

        $entry = $request->user()->entries()->create([
            'body'            => $data['body'],
            'daily_intent_id' => $data['daily_intent_id'] ?? null,
            'in_vault'        => $data['in_vault'] ?? false,
        ]);

        // 2026-08-18: bump the day's DailyReview.last_activity_at on every
        // entry — this, not a fixed daily boundary, is what the daily
        // breakdown review's 24h auto-confirm window counts from (see
        // DailyReview::maybeAutoConfirm() and docs/dashboard-logic.md).
        // updateOrCreate so the row exists from the first entry of the day
        // onward, same as DailyIntentController::store() does for picks —
        // independent of whether the user ever sets a pick or clicks save.
        DailyReview::updateOrCreate(
            ['user_id' => $request->user()->id, 'date' => $entry->created_at->toDateString()],
            ['last_activity_at' => $entry->created_at]
        );

        // 2026-11-07: clear prompt-injection attempts never reach the AI.
        // The entry is still saved (it's what they wrote, and it shows on
        // /admin), but instead of the extraction + coach jobs it gets a fixed
        // reply and a minimal "not analyzed" row, and the owner is emailed.
        // Crisis language ALWAYS wins: an entry that reads as a crisis goes
        // through normal crisis handling even if it also trips the guard.
        $rule = InjectionGuard::match($data['body']);
        if ($rule !== null && !$entry->in_vault && !app(CoachService::class)->isCrisisText($data['body'])) {
            CoachMessage::create([
                'entry_id' => $entry->id,
                'mode'     => 'hold_back',
                'body'     => InjectionGuard::reply($request->user()),
                'reason'   => 'prompt_injection_blocked: '.$rule,
            ]);
            app(EntryExtractionService::class)->fallbackFor($entry, 'prompt_injection_blocked: '.$rule);
            InjectionGuard::report($request->user(), $data['body'], $rule, 'journal entry');

            return response()->json(array_merge($entry->load('coachMessage')->toArray(), ['blocked' => true]), 201);
        }

        if (!$entry->in_vault) {
            // Both jobs are dispatched to the queue so the HTTP response returns
            // immediately — no more 30-second hang waiting for the NVIDIA API.
            // Run `php artisan queue:work` in api/ to process them.
            ExtractEntryJob::dispatch($entry);
            GenerateCoachMessageJob::dispatch($entry);
        }

        return response()->json($entry, 201);
    }
}
