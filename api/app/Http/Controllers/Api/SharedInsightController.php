<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsightSnapshot;
use App\Models\Share;
use App\Models\TherapistClient;
use App\Models\User;
use App\Services\EmotionPatternService;
use App\Services\NvidiaClient;
use App\Services\TagBreakdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 2026-08-18, "share to therapist" — a little button in the upper corner
 * of every weekly (Dashboard.vue) and monthly (MonthlyDashboard.vue)
 * panel. See InsightSnapshot's docblock and docs/therapist-bridge.md §1
 * for why this builds on the day-one `shares` scaffolding (per-item
 * consent, revocable) instead of a parallel mechanism — the design spec
 * (`docs/sujet-journal-ai (1).md`) was explicit that "nothing is
 * shareable without an explicit per-item action," which this follows: a
 * share is one panel, at one moment, chosen once by the client, and
 * revocable at any time (§"Consent gates every boundary").
 *
 * No Policy layer, matching every other controller in this app —
 * ownership/role checks are inline (same style as TherapistLinkController).
 */
class SharedInsightController extends Controller
{
    // 2026-08-19: 'pattern' added for sharing one specific all-time pattern
    // chain (trigger -> emotion/thinking_pattern/behavior, see
    // Dashboard.vue's chainRows / PatternController) -- user: "i want to be
    // able to share patterns across your entries and choose which one to
    // share." Not week/month/thread-scoped since a chain is computed
    // across every reliable entry, not one window. No special-casing
    // needed elsewhere in this controller: attachComparisonContext() and
    // the daily-pattern computation already gate on scope==='week' /
    // panel_key==='emotion_breakdown' and simply skip a 'pattern' share;
    // attachTrajectoryContext() already runs for every scope, so
    // re-sharing the same chain later gets "last shared N ago" diffing
    // for free.
    private const SCOPES = ['week', 'month', 'thread', 'pattern'];

    // 2026-08-19: comparison context for weekly tag-breakdown shares (user:
    // "how do we make [shared insights] more suitable and helpful to the
    // therapist not just copy paste"). A weekly panel's rows were a frozen
    // single-week number with nothing to compare it against -- a therapist
    // had to already know this client's baseline to tell whether "sadness
    // 40%" was notable or nothing new. This maps each weekly panel_key to
    // the same {column, labelKey, weightKey} triple WeeklyReflectionController
    // uses via TagBreakdownService, so the comparison is computed with the
    // exact same definitions the client's own dashboard already uses --
    // 'mood' and thread/month-scope shares aren't tag breakdowns and are
    // deliberately left out of this map (month-scope shares already carry
    // trend framing from MonthlyDashboardController).
    private const PANEL_FIELD_MAP = [
        'emotion_breakdown'           => ['emotions', 'emotion', 'intensity'],
        'stressor_breakdown'          => ['stressors', 'category', 'impact'],
        'protective_factor_breakdown' => ['protective_factors', 'factor', 'impact'],
        'behavior_breakdown'          => ['behaviors', 'behavior', 'impact'],
        'need_breakdown'              => ['needs', 'need', null],
        'thinking_pattern_breakdown'  => ['thinking_patterns', 'pattern', null],
    ];
    private const BASELINE_WEEKS        = 6;
    private const MIN_BASELINE_ENTRIES  = 2;
    // Same up/down threshold MonthlyDashboardController::directionFor()
    // uses -- one shared definition of "meaningfully different" rather
    // than inventing a second one for this feature.
    private const DELTA_THRESHOLD       = 0.10;

    /**
     * POST /api/shared-insights
     * Client-only. Freezes whatever the frontend is showing on screen for
     * one panel into an InsightSnapshot, then wraps it in a Share row
     * (granted_at = now) — the two are created together, deliberately (see
     * InsightSnapshot::share(), a real one-to-one in practice). Requires an
     * active therapist link; sharing into a void nobody can see isn't a
     * state worth creating.
     */
    public function store(Request $request): JsonResponse
    {
        $client = $request->user();
        if ($client->role !== 'client') {
            return response()->json(['message' => 'Only client accounts can share insights.'], 403);
        }

        if (!$client->therapistLink()->exists()) {
            return response()->json(['message' => 'Link a therapist first before sharing.'], 422);
        }

        $validated = $request->validate([
            'panel_key'    => ['required', 'string', 'max:100'],
            'scope'        => ['required', 'string', 'in:' . implode(',', self::SCOPES)],
            'title'        => ['required', 'string', 'max:255'],
            'period_label' => ['required', 'string', 'max:255'],
            'caption'      => ['nullable', 'string', 'max:1000'],
            'rows'         => ['required', 'array', 'min:1'],
            'rows.*.label'  => ['required', 'string'],
            'rows.*.detail' => ['nullable', 'string'],
            'rows.*.rate'   => ['nullable', 'numeric'],
            'rows.*.width'  => ['nullable', 'numeric'],
            'rows.*.filled' => ['nullable', 'integer'],
            'rows.*.share'  => ['nullable', 'numeric'],
            'rows.*.tone'   => ['nullable', 'string', 'max:20'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ]);

        // Only weekly tag-breakdown panels get a baseline comparison -- see
        // attachComparisonContext()'s docblock. Trajectory-vs-last-share
        // runs for every scope/panel afterward and backs off wherever a
        // row already has a baseline context, so a row never carries two
        // comparison sentences at once.
        $rows = $validated['scope'] === 'week'
            ? $this->attachComparisonContext($client, $validated['panel_key'], $validated['rows'])
            : $validated['rows'];
        $rows = $this->attachTrajectoryContext($client, $validated['panel_key'], $rows);

        // 2026-08-19: day-by-day diagram data (user: "make the insights...
        // detailed by day and what are the triggers and the time when the
        // person feels it") -- only for the weekly emotion breakdown, the
        // one panel this framing actually applies to. See
        // EmotionPatternService's docblock.
        $dailyPattern = null;
        if ($validated['scope'] === 'week' && $validated['panel_key'] === 'emotion_breakdown') {
            [$weekStart, $weekEnd] = $this->currentWeekBounds();
            $pattern = EmotionPatternService::weekly($client, $weekStart, $weekEnd);
            $dailyPattern = $pattern ?: null;
        }

        $snapshot = InsightSnapshot::create([
            'user_id'      => $client->id,
            'panel_key'    => $validated['panel_key'],
            'scope'        => $validated['scope'],
            'title'        => $validated['title'],
            'period_label' => $validated['period_label'],
            'caption'      => $validated['caption'] ?? null,
            'rows'         => $rows,
            'note'         => $validated['note'] ?? null,
            'daily_pattern' => $dailyPattern,
        ]);

        $share = Share::create([
            'user_id'        => $client->id,
            'shareable_type' => InsightSnapshot::class,
            'shareable_id'   => $snapshot->id,
            'granted_at'     => Carbon::now(),
        ]);

        return response()->json([
            'share_id' => $share->id,
            'snapshot' => $snapshot,
        ], 201);
    }

    /**
     * GET /api/shared-insights
     * Client-only. Their own share history, newest first, including
     * revoked ones (shown greyed-out client-side) — this is also how
     * Dashboard.vue/MonthlyDashboard.vue know which panel+period pairs are
     * "already shared" so the button can reflect that instead of always
     * looking un-clicked.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Only client accounts have shared insights.'], 403);
        }

        $shares = Share::where('user_id', $user->id)
            ->where('shareable_type', InsightSnapshot::class)
            ->with('shareable')
            ->latest('granted_at')
            ->get()
            ->map(fn (Share $s) => $this->formatShare($s));

        return response()->json($shares->values());
    }

    /**
     * DELETE /api/shared-insights/{share}
     * "Unshare" — sets revoked_at rather than deleting the row, so the
     * client's own history (index() above) still shows it happened. Must
     * be the client who created it; a therapist never revokes on someone
     * else's behalf.
     */
    public function destroy(Request $request, Share $share): JsonResponse
    {
        $user = $request->user();
        if ($share->user_id !== $user->id) {
            return response()->json(['message' => 'Not your share.'], 403);
        }

        if (!$share->revoked_at) {
            $share->revoked_at = Carbon::now();
            $share->save();
        }

        return response()->json($share);
    }

    /**
     * GET /api/shared-insights/client/{clientId}
     * Therapist-only. Only currently-active (non-revoked) shares for a
     * client they're actually linked to — inline ownership check via
     * TherapistClient, same pattern CrisisThreadController uses. A revoked
     * share never reaches here at all, not even marked as withdrawn —
     * withdrawing consent means it's gone from this view, full stop.
     */
    public function forClient(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can view a client\'s shared insights.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $shares = Share::where('user_id', $clientId)
            ->where('shareable_type', InsightSnapshot::class)
            ->whereNull('revoked_at')
            ->with('shareable')
            ->latest('granted_at')
            ->get()
            ->map(fn (Share $s) => $this->formatShare($s));

        return response()->json($shares->values());
    }

    /**
     * PATCH /api/shared-insights/{share}/note
     * Therapist-only. A private annotation on a shared insight, separate
     * from the client's own `note` -- same shape as
     * TherapySession::outcome_note. Only settable on a share that's
     * currently active (non-revoked) and actually theirs to see; an empty
     * string clears it back to null rather than leaving a stray "".
     */
    public function updateTherapistNote(Request $request, Share $share): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can annotate a shared insight.'], 403);
        }
        if ($share->shareable_type !== InsightSnapshot::class || $share->revoked_at) {
            return response()->json(['message' => 'Not available.'], 404);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $share->user_id)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $validated = $request->validate([
            'therapist_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $snapshot = $share->shareable;
        $snapshot->therapist_note = ($validated['therapist_note'] ?? '') === '' ? null : $validated['therapist_note'];
        $snapshot->save();

        return response()->json($this->formatShare($share->fresh('shareable')));
    }

    /**
     * Attaches a `context` string to each row of a weekly tag-breakdown
     * share -- "up from ~22% typically over the past 6 weeks" instead of a
     * bare number with nothing to compare it against. Recomputes BOTH the
     * current week's rate and the baseline rate here rather than trusting
     * whatever the frontend sent in `rows` -- the frontend's `detail`
     * string is free-form display text (not a parseable number), and this
     * way the comparison is always internally consistent (same
     * TagBreakdownService call, same reliability filter) even if it can't
     * be, since $rows already went through validation, out of sync with
     * exactly what the client's screen showed at the literal moment they
     * clicked share a few seconds earlier.
     *
     * Silently returns $rows unchanged (no context added) when: panel_key
     * isn't a mapped tag breakdown, the client doesn't have at least
     * MIN_BASELINE_ENTRIES analyses in the baseline window (not enough
     * history to say anything meaningful yet), or a specific row's label
     * doesn't turn up in this week's own breakdown at all (defensive --
     * shouldn't happen with an unmodified client, but never fabricate a
     * comparison from a label the server can't verify).
     */
    private function attachComparisonContext(User $client, string $panelKey, array $rows): array
    {
        if (!isset(self::PANEL_FIELD_MAP[$panelKey])) {
            return $rows;
        }
        [$column, $labelKey, $weightKey] = self::PANEL_FIELD_MAP[$panelKey];

        [$weekStart, $weekEnd] = $this->currentWeekBounds();
        $baselineStart = $weekStart->copy()->subWeeks(self::BASELINE_WEEKS);
        $baselineEnd   = $weekStart->copy()->subSecond();

        $reliable = fn ($a) => $a->extraction_confidence === null || $a->extraction_confidence >= 0.4;

        $currentAnalyses = $client->entries()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->with('analysis')->get()
            ->pluck('analysis')->filter()->filter($reliable);

        $baselineAnalyses = $client->entries()
            ->whereBetween('created_at', [$baselineStart, $baselineEnd])
            ->with('analysis')->get()
            ->pluck('analysis')->filter()->filter($reliable);

        if ($baselineAnalyses->count() < self::MIN_BASELINE_ENTRIES) {
            return $rows;
        }

        // High limit (well above any real label count for these fields) so
        // this is effectively "every label," not just a top-5 slice --
        // unlike the dashboard displays, this lookup needs to find
        // whichever specific labels are already in $rows, not rank them.
        $currentMap = collect(TagBreakdownService::breakdown($currentAnalyses, $column, $labelKey, $weightKey, 50))
            ->keyBy(fn ($r) => mb_strtolower($r['label']));
        $baselineMap = collect(TagBreakdownService::breakdown($baselineAnalyses, $column, $labelKey, $weightKey, 50))
            ->keyBy(fn ($r) => mb_strtolower($r['label']));

        foreach ($rows as &$row) {
            $key = mb_strtolower($row['label'] ?? '');
            $currentRate = $currentMap[$key]['rate'] ?? null;
            if ($currentRate === null) {
                continue;
            }

            $baselineRate = $baselineMap[$key]['rate'] ?? 0.0;

            if ($baselineRate <= 0.0) {
                $row['context'] = 'new this week — hasn\'t shown up in the prior ' . self::BASELINE_WEEKS . ' weeks.';
                $row['flag']    = 'new';
                continue;
            }

            $delta       = $currentRate - $baselineRate;
            $baselinePct = round($baselineRate * 100);

            if ($delta > self::DELTA_THRESHOLD) {
                $row['context'] = "up from ~{$baselinePct}% typically over the past " . self::BASELINE_WEEKS . ' weeks.';
                $row['flag']    = 'up';
            } elseif ($delta < -self::DELTA_THRESHOLD) {
                $row['context'] = "down from ~{$baselinePct}% typically over the past " . self::BASELINE_WEEKS . ' weeks.';
                $row['flag']    = 'down';
            } else {
                $row['context'] = "about typical for you (~{$baselinePct}% over the past " . self::BASELINE_WEEKS . ' weeks).';
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Attaches a `trajectory` string comparing this row against the same
     * client's PREVIOUS share of the same panel_key -- "last shared 2
     * weeks ago, was 'sometimes', now 'most days'" -- rather than the
     * synthetic weekly baseline attachComparisonContext() computes.
     * Deliberately generic across every scope (week/month/thread) and
     * every panel_key, unlike attachComparisonContext(): it doesn't
     * recompute anything from entry_analyses, it just diffs the frozen
     * `detail` string against the last time this exact panel was shared.
     * `detail` is free-form display text across panels (a frequency word,
     * a count, a sentence -- see the openShareConfirm() call sites in
     * Dashboard.vue/MonthlyDashboard.vue), never reliably a parseable
     * number, so this compares it as text rather than pretending it's
     * always a percentage.
     *
     * Skips a row entirely (no `trajectory` added) when: there is no
     * prior share of this panel_key at all (nothing to compare against),
     * the row already got a `context` from attachComparisonContext()
     * (don't stack two comparison sentences on one row), the label didn't
     * appear in the previous share, or the detail text is identical to
     * last time (nothing changed worth flagging).
     */
    private function attachTrajectoryContext(User $client, string $panelKey, array $rows): array
    {
        $previous = InsightSnapshot::where('user_id', $client->id)
            ->where('panel_key', $panelKey)
            ->latest('created_at')
            ->first();

        if (!$previous) {
            return $rows;
        }

        $prevRows = collect($previous->rows ?? [])
            ->keyBy(fn ($r) => mb_strtolower($r['label'] ?? ''));
        $ago = $previous->created_at->diffForHumans();

        foreach ($rows as &$row) {
            if (isset($row['context'])) {
                continue;
            }
            $prev = $prevRows[mb_strtolower($row['label'] ?? '')] ?? null;
            if (!$prev) {
                continue;
            }
            $prevDetail = trim((string) ($prev['detail'] ?? ''));
            $curDetail  = trim((string) ($row['detail'] ?? ''));
            if ($prevDetail === '' || $curDetail === $prevDetail) {
                continue;
            }
            $row['trajectory'] = "last shared {$ago} — was \"{$prevDetail}\", now \"{$curDetail}\".";
        }
        unset($row);

        return $rows;
    }

    /**
     * Same Monday-start week boundary WeeklyReflectionController::
     * generateContent() uses, so "this week" means the same thing here as
     * it did on the screen the client just shared from. Shared between
     * attachComparisonContext() and store()'s daily-pattern computation so
     * both always agree on what "this week" is, rather than two separately
     * hand-written copies of the same formula drifting apart.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentWeekBounds(): array
    {
        return [
            Carbon::now()->startOfWeek(Carbon::MONDAY),
            Carbon::now()->endOfWeek(Carbon::SUNDAY)->endOfDay(),
        ];
    }

    /**
     * POST /api/shared-insights/chat/{clientId}
     * Therapist-only. An AI assistant that answers questions about a linked
     * client's shared insights, using the same NvidiaClient every other
     * AI feature in this app uses.
     */
    public function chat(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can use the insights chat.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $shares = Share::where('user_id', $clientId)
            ->where('shareable_type', InsightSnapshot::class)
            ->whereNull('revoked_at')
            ->with('shareable')
            ->latest('granted_at')
            ->get();

        $contextLines = $shares->map(function (Share $share) {
            $snap = $share->shareable;
            if (!$snap) return null;
            $rows = collect($snap->rows ?? [])
                ->map(fn ($r) => "{$r['label']}: {$r['detail']}")
                ->join(', ');
            return "• {$snap->title} ({$snap->period_label}): {$rows}";
        })->filter()->join("\n");

        if (!$contextLines) {
            return response()->json(['reply' => 'No shared insights available yet for this client.']);
        }

        $ai = app(NvidiaClient::class);
        $reply = $ai->chat([
            [
                'role'    => 'system',
                'content' => 'You are a concise clinical assistant helping a therapist understand their client\'s emotional and behavioral patterns from shared journal insights. Answer only from the data provided. Be clear, brief, and clinically useful — no disclaimers or padding.',
            ],
            [
                'role'    => 'user',
                'content' => "Client's shared insights:\n{$contextLines}\n\nQuestion: {$validated['question']}",
            ],
        ], ['temperature' => 0.4, 'max_tokens' => 500]);

        return response()->json(['reply' => $reply ?? 'Could not get a response — please try again.']);
    }

    private function formatShare(Share $share): array
    {
        $snapshot = $share->shareable;
        return [
            'share_id'     => $share->id,
            'granted_at'   => $share->granted_at,
            'revoked_at'   => $share->revoked_at,
            'panel_key'    => $snapshot?->panel_key,
            'scope'        => $snapshot?->scope,
            'title'        => $snapshot?->title,
            'period_label' => $snapshot?->period_label,
            'caption'      => $snapshot?->caption,
            'rows'         => $snapshot?->rows,
            'note'         => $snapshot?->note,
            'therapist_note' => $snapshot?->therapist_note,
            'daily_pattern'  => $snapshot?->daily_pattern,
        ];
    }
}
