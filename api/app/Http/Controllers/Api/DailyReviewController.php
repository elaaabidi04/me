<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisCorrection;
use App\Models\DailyReview;
use App\Services\TagBreakdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 2026-08-18, user-requested daily breakdown review — see docs/dashboard-
 * logic.md for the full design writeup. Four scope decisions were
 * confirmed with the user before this was built: whole-day (not
 * per-entry), non-gating (review is a chance to look/correct, never holds
 * data back from dashboards/patterns/coach memory — those already use
 * extracted data live, same as every other confirm/correct flow in this
 * app), an in-app banner (no push/email notification system exists), and
 * a 24h-from-last-activity auto-confirm window (see DailyReview::
 * maybeAutoConfirm()).
 */
class DailyReviewController extends Controller
{
    // Maps the URL-facing field name (also the actual EntryAnalysis JSON
    // column) to what TagBreakdownService::breakdown() needs, and to the
    // *_breakdown response key WeeklyReflectionController already uses —
    // deliberately the same shape so Dashboard.vue's breakdownRows()/warm()
    // helpers can be reused as-is for this view too.
    private const FIELD_SPEC = [
        'emotions'           => ['label_key' => 'emotion',   'weight_key' => 'intensity', 'breakdown_key' => 'emotion_breakdown'],
        'stressors'          => ['label_key' => 'category',  'weight_key' => 'impact',    'breakdown_key' => 'stressor_breakdown'],
        'protective_factors' => ['label_key' => 'factor',    'weight_key' => 'impact',    'breakdown_key' => 'protective_factor_breakdown'],
        'behaviors'          => ['label_key' => 'behavior',  'weight_key' => 'impact',    'breakdown_key' => 'behavior_breakdown'],
        'needs'              => ['label_key' => 'need',      'weight_key' => null,        'breakdown_key' => 'need_breakdown'],
        'thinking_patterns'  => ['label_key' => 'pattern',   'weight_key' => null,        'breakdown_key' => 'thinking_pattern_breakdown'],
    ];

    /**
     * GET /api/daily-review/pending
     * The oldest day still waiting on a real look — saved by the user but
     * neither reviewed nor (yet) auto-confirmed. Same "oldest unreviewed
     * first" shape as CanonEventController::pending() — the queue actually
     * empties instead of always re-showing the newest thing. Lazily
     * auto-confirms every outstanding row for this user first (see
     * DailyReview::maybeAutoConfirm()'s docblock for why this is lazy, not
     * a background sweep), so a day that aged past 24h stops showing up
     * here even if nobody ever explicitly visited it.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $outstanding = DailyReview::where('user_id', $user->id)
            ->whereNull('reviewed_at')
            ->whereNull('auto_confirmed_at')
            ->whereNotNull('saved_at')
            ->orderBy('date')
            ->get();

        foreach ($outstanding as $row) {
            $row->maybeAutoConfirm();
        }

        $next = $outstanding->first(fn ($row) => $row->status === 'pending_review');

        if (!$next) {
            return response()->json(null);
        }

        $entryCount = $user->entries()->whereDate('created_at', $next->date)->count();

        return response()->json([
            // 'date' is a plain 'Y-m-d' string (no cast) -- see DailyReview's
            // casts() docblock. Do not call ->toDateString() on it.
            'date'        => $next->date,
            'saved_at'    => $next->saved_at,
            'entry_count' => $entryCount,
        ]);
    }

    /**
     * GET /api/daily-review/{date}
     * The breakdown for one specific day (YYYY-MM-DD), same six
     * *_breakdown fields WeeklyReflectionController returns, computed only
     * from that day's entries via the same reliability floor (extraction_
     * confidence null or >= 0.4) used everywhere else this app aggregates
     * extracted tags. Works for any past day, not just "today" — the
     * pending queue above can point at an older, unsaved-until-now day.
     */
    public function show(Request $request, string $date): JsonResponse
    {
        $parsed = $this->parseDate($date);
        if (!$parsed) {
            return response()->json(['message' => 'invalid date'], 422);
        }

        $user = $request->user();

        $review = DailyReview::where('user_id', $user->id)->whereDate('date', $parsed)->first();
        $review?->maybeAutoConfirm();

        $analyses = $this->reliableAnalysesFor($user, $parsed);

        $result = [
            'date'              => $parsed->toDateString(),
            'status'            => $review?->status ?? 'in_progress',
            'entry_count'       => $analyses->count(),
            'saved_at'          => $review?->saved_at,
            'reviewed_at'       => $review?->reviewed_at,
            'auto_confirmed_at' => $review?->auto_confirmed_at,
            'last_activity_at'  => $review?->last_activity_at,
        ];

        foreach (self::FIELD_SPEC as $column => $spec) {
            $result[$spec['breakdown_key']] = TagBreakdownService::breakdown(
                $analyses, $column, $spec['label_key'], $spec['weight_key']
            );
        }

        return response()->json($result);
    }

    /**
     * POST /api/daily-review/{date}/save
     * "Save the journal" — the explicit action that gates whether the
     * "want to see today's breakdown?" banner ever appears at all (no
     * save, no banner — just the silent 24h-from-last-activity
     * auto-confirm fallback, unaffected by this endpoint). Requires at
     * least one entry that day; saving an empty day makes no sense and
     * would create a DailyReview row with nothing to review.
     */
    public function save(Request $request, string $date): JsonResponse
    {
        $parsed = $this->parseDate($date);
        if (!$parsed) {
            return response()->json(['message' => 'invalid date'], 422);
        }

        $user = $request->user();
        $entryCount = $user->entries()->whereDate('created_at', $parsed)->count();
        if ($entryCount === 0) {
            return response()->json(['message' => 'no entries on this day yet'], 422);
        }

        $review = DailyReview::updateOrCreate(
            ['user_id' => $user->id, 'date' => $parsed->toDateString()],
            ['saved_at' => Carbon::now()]
        );

        return response()->json($review);
    }

    /**
     * POST /api/daily-review/{date}/mark-reviewed
     * Fired when the user actually opens the breakdown drawer for this day
     * (see Journal.vue) — "then he sees" from the user's own description
     * of the flow is treated as the review itself; correcting individual
     * tags inside the drawer is optional on top of that, via the existing
     * /api/analysis-corrections endpoint (no new correction mechanism —
     * see show()'s drillThrough sibling below for how a label maps back to
     * the specific entries a correction would target).
     */
    public function markReviewed(Request $request, string $date): JsonResponse
    {
        $parsed = $this->parseDate($date);
        if (!$parsed) {
            return response()->json(['message' => 'invalid date'], 422);
        }

        $review = DailyReview::updateOrCreate(
            ['user_id' => $request->user()->id, 'date' => $parsed->toDateString()],
            ['reviewed_at' => Carbon::now()]
        );

        return response()->json($review);
    }

    /**
     * GET /api/daily-review/{date}/{column}/{value}
     * Drill-through: which of that day's entries carry this label (after
     * corrections are applied, same resolution TagBreakdownService uses),
     * each with its entry_analysis_id + original_value so the frontend can
     * fire the existing POST /api/analysis-corrections with exactly the
     * pair that endpoint expects — this view doesn't reimplement
     * corrections, it just tells you which (analysis, original label) a
     * correction should target. Mirrors PatternController::show()'s
     * drill-through shape, scoped to one day instead of all-time.
     */
    public function drillThrough(Request $request, string $date, string $column, string $value): JsonResponse
    {
        $parsed = $this->parseDate($date);
        if (!$parsed || !isset(self::FIELD_SPEC[$column])) {
            return response()->json(['message' => 'invalid date or field'], 422);
        }

        $labelKey = self::FIELD_SPEC[$column]['label_key'];
        $user     = $request->user();

        $entries = $user->entries()
            ->whereDate('created_at', $parsed)
            ->with('analysis')
            ->get()
            ->filter(fn ($e) => $e->analysis
                && ($e->analysis->extraction_confidence === null || $e->analysis->extraction_confidence >= 0.4));

        $analysisIds = $entries->map(fn ($e) => $e->analysis->id)->all();
        $correctionMap = [];
        if (!empty($analysisIds)) {
            AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)
                ->where('field', $column)
                ->get()
                ->each(function ($c) use (&$correctionMap) {
                    $correctionMap["{$c->entry_analysis_id}:{$c->original_value}"] = $c;
                });
        }

        $matches = [];
        foreach ($entries as $entry) {
            $analysis = $entry->analysis;
            foreach ($analysis->{$column} ?? [] as $item) {
                $originalLabel = $labelKey ? ($item[$labelKey] ?? null) : $item;
                if (!$originalLabel) {
                    continue;
                }

                $label = $originalLabel;
                $correction = $correctionMap["{$analysis->id}:{$originalLabel}"] ?? null;
                if ($correction) {
                    if ($correction->action === 'removed') {
                        continue;
                    }
                    if ($correction->action === 'corrected' && $correction->corrected_value) {
                        $label = $correction->corrected_value;
                    }
                }

                if ($label !== $value) {
                    continue;
                }

                $matches[] = [
                    'entry_id'           => $entry->id,
                    'entry_analysis_id'  => $analysis->id,
                    'created_at'         => $entry->created_at->toIso8601String(),
                    'body'               => $entry->body,
                    'original_value'     => $originalLabel,
                    'already_corrected'  => $correction !== null,
                ];
            }
        }

        usort($matches, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return response()->json([
            'date' => $parsed->toDateString(), 'field' => $column, 'value' => $value,
            'count' => count($matches), 'entries' => $matches,
        ]);
    }

    private function reliableAnalysesFor($user, Carbon $date)
    {
        return $user->entries()
            ->whereDate('created_at', $date)
            ->with('analysis')
            ->get()
            ->pluck('analysis')
            ->filter()
            ->filter(fn ($a) => $a->extraction_confidence === null || $a->extraction_confidence >= 0.4)
            ->values();
    }

    private function parseDate(string $date): ?Carbon
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Exception $e) {
            return null;
        }
        // No browsing into the future — a day that hasn't happened yet has
        // nothing to review, same ceiling MonthlyDashboardController
        // applies to end_month.
        if ($parsed->gt(Carbon::today())) {
            return null;
        }
        return $parsed;
    }
}
