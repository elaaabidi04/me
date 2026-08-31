<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisCorrection;
use App\Models\TherapistClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Month-over-month trend view (2026-08-17, user-requested: "i want u to
 * create monthly dashboard"). Deliberately scoped to TRENDS across a
 * rolling window, not a single month's snapshot -- when asked to choose,
 * the user picked "month-over-month trends" over "same breakdowns as the
 * weekly view, just monthly," plus history browsing via a focus-month
 * parameter. See docs/dashboard-logic.md for the fuller writeup.
 *
 * Kept as its own controller rather than extending/refactoring
 * WeeklyReflectionController -- the aggregation shape is different enough
 * (a FIXED set of labels tracked across N month buckets, vs. weekly's
 * single-bucket top-N) that sharing code would mean either bending
 * WeeklyReflectionController's already-shipped, working tagBreakdown() to
 * serve two shapes, or extracting a shared service and touching stable
 * code for a net-new feature. Same "known overlap, not merged" tradeoff
 * already made elsewhere in this codebase (see dashboard-logic.md §3's
 * strategy_hint/behaviors note) -- some duplication accepted deliberately
 * to avoid destabilizing a working feature.
 */
class MonthlyDashboardController extends Controller
{
    // 2026-08-17 (follow-up): window size is now user-adjustable (a
    // "zoom" control in MonthlyDashboard.vue) rather than fixed at 6 --
    // this is only the fallback when the request omits ?months=. MIN/MAX
    // guard against a pathological request (0 months, or a window large
    // enough to make the top-label ranking and correction lookup scan an
    // unreasonable number of rows).
    // 2026-08-19: default zoom dropped to 2mo to match the frontend's
    // DEFAULT_WINDOW (MonthlyDashboard.vue + ClientTrends.vue) -- kept
    // this in lockstep even though both callers always pass ?months=
    // explicitly, so the fallback stays truthful if that ever changes.
    private const DEFAULT_WINDOW_MONTHS = 2;
    private const MIN_WINDOW_MONTHS     = 2;
    private const MAX_WINDOW_MONTHS     = 24;
    private const TOP_LABELS            = 5;

    // 2026-08-17 (second follow-up): plotted resolution is now WEEKLY, not
    // monthly -- user feedback was that one point per month made every
    // line "just a line," no visible ups and downs, and asked for
    // "entries by week." Window size/navigation (end_month, ?months=,
    // the zoom control) stay month-denominated -- only the data points
    // inside that window changed granularity. A week's presence rate is
    // real signal even from a single entry (a journaling week can
    // legitimately have just one entry), so the bar for "usable" in
    // directionFor() below is intentionally lower than a month's ever was.
    private const MIN_ENTRIES_PER_WEEK_FOR_TREND = 1;

    /**
     * Same {column, labelKey, weightKey} shape WeeklyReflectionController
     * uses per field -- see that class's generateContent() for the
     * original definitions this mirrors.
     */
    private const FIELDS = [
        'emotion'           => ['column' => 'emotions',           'labelKey' => 'emotion',  'weightKey' => 'intensity'],
        'stressor'          => ['column' => 'stressors',          'labelKey' => 'category', 'weightKey' => 'impact'],
        'protective_factor' => ['column' => 'protective_factors', 'labelKey' => 'factor',   'weightKey' => 'impact'],
        'behavior'          => ['column' => 'behaviors',          'labelKey' => 'behavior', 'weightKey' => 'impact'],
        'need'              => ['column' => 'needs',              'labelKey' => 'need',     'weightKey' => null],
        // Experimental field, expect this to come back sparse/empty far
        // more often than the rest -- same caveat as everywhere else this
        // field is surfaced (see docs/dashboard-logic.md §7 #12).
        'thinking_pattern'  => ['column' => 'thinking_patterns',  'labelKey' => 'pattern',  'weightKey' => null],
    ];

    /**
     * GET /api/monthly/trends?end_month=YYYY-MM&months=N
     *
     * Live (ungated) view -- deliberately no seal/lock ritual the way
     * WeeklyReflectionController::current() has. That ritual exists for a
     * once-a-week reveal; this page is for exploratory browsing across
     * past months, so it always computes fresh, the same way
     * /reflections/live does for the current week.
     *
     * The WINDOW is a rolling N-month span ending at end_month (N from
     * ?months=, default DEFAULT_WINDOW_MONTHS, clamped to
     * [MIN_WINDOW_MONTHS, MAX_WINDOW_MONTHS]) -- end_month itself defaults
     * to, and is clamped to never exceed, the current month, since there's
     * no such thing as browsing into the future. That much is unchanged
     * from the first version of this endpoint.
     *
     * What changed (second follow-up, same day): the DATA POINTS inside
     * that window are now WEEKLY buckets (Monday-start, matching
     * WeeklyReflectionController's own convention), not one point per
     * calendar month. A single monthly aggregate smooths real week-to-week
     * variation into a flat line; weekly buckets are what actually let a
     * line show ups and downs. For each tagged field, the top TOP_LABELS
     * labels are still chosen by weighted total across the WHOLE window
     * (not re-picked per week), for the same reason as before -- a label
     * popping in and out of the top-5 from re-ranking alone would make the
     * line meaningless. Every top label gets a rate for every week in the
     * window: 0.0 if it didn't appear that week, null only if the week had
     * zero reliable entries at all (distinct from "definitely didn't
     * happen").
     */
    /**
     * GET /api/monthly/trends?end_month=YYYY-MM&months=N
     *
     * Live (ungated) view for the authenticated client -- see the class
     * docblock for the design tradeoffs. Delegates to buildTrends().
     */
    public function trends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'end_month' => ['nullable', 'date_format:Y-m'],
            'months'    => ['nullable', 'integer', 'min:' . self::MIN_WINDOW_MONTHS, 'max:' . self::MAX_WINDOW_MONTHS],
        ]);

        return response()->json(
            $this->buildTrends($request->user(), $validated)
        );
    }

    /**
     * GET /api/monthly/trends/client/{clientId}?end_month=YYYY-MM&months=N
     * Therapist-only. Returns the SAME weekly-granularity trend data as
     * trends(), but scoped to a linked client's entries. 403s unless:
     *   1. the authenticated user is a therapist,
     *   2. an active TherapistClient link exists between them,
     *   3. live_trends_enabled is TRUE on that link (client's opt-in).
     * Check 3 is live on every request — turning the toggle off in
     * MyTherapist.vue cuts access immediately, no caching involved (same
     * "check when you check" pattern as every other live flag in this app).
     */
    public function trendsForClient(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can view a client\'s trends.'], 403);
        }

        $link = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->first();

        if (!$link) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        if (!$link->live_trends_enabled) {
            return response()->json(['message' => 'Live trend sharing is not enabled for this client.'], 403);
        }

        $client = User::find($clientId);
        if (!$client) {
            return response()->json(['message' => 'Client not found.'], 404);
        }

        $validated = $request->validate([
            'end_month' => ['nullable', 'date_format:Y-m'],
            'months'    => ['nullable', 'integer', 'min:' . self::MIN_WINDOW_MONTHS, 'max:' . self::MAX_WINDOW_MONTHS],
        ]);

        return response()->json(
            $this->buildTrends($client, $validated)
        );
    }

    /**
     * Core trend computation, shared by trends() (for the authenticated
     * client) and trendsForClient() (therapist viewing a linked client).
     * Extracted so trendsForClient() can pass a different User without
     * duplicating the bucket/aggregation logic.
     *
     * @param array{end_month?: string|null, months?: int|null} $validated
     */
    private function buildTrends(User $user, array $validated): array
    {
        $windowMonths = $validated['months'] ?? self::DEFAULT_WINDOW_MONTHS;

        $endMonth = !empty($validated['end_month'])
            ? Carbon::createFromFormat('Y-m', $validated['end_month'])->startOfMonth()
            : now()->startOfMonth();

        // Clamp -- never allow navigating into a month that hasn't started.
        if ($endMonth->gt(now()->startOfMonth())) {
            $endMonth = now()->startOfMonth();
        }

        $startMonth = $endMonth->copy()->subMonths($windowMonths - 1);
        $windowEnd  = $endMonth->copy()->endOfMonth();
        // Clamp the trailing edge to "now" when end_month is the current
        // month -- otherwise weekly buckets would extend into days that
        // haven't happened yet, showing up as a dangling empty/null week
        // at the end of every chart for no reason.
        if ($windowEnd->gt(now())) {
            $windowEnd = now();
        }

        // Snap the month-based window onto whole weeks -- the first bucket
        // is the Monday-start week containing $startMonth's first day, so
        // a window that begins mid-week doesn't lose or double-count that
        // week's entries.
        $weekCursorStart = $startMonth->copy()->startOfWeek(Carbon::MONDAY);

        $entries = $user->entries()
            ->whereBetween('created_at', [$weekCursorStart, $windowEnd])
            ->with('analysis')
            ->get();

        // Week buckets, oldest first, pre-seeded so a week with zero
        // entries still shows up as an empty bucket rather than a gap.
        $weeks = [];
        $cursor = $weekCursorStart->copy();
        while ($cursor->lte($windowEnd)) {
            $weeks[] = $cursor->format('Y-m-d'); // the Monday date, sortable and unique
            $cursor->addWeek();
        }

        /** @var array<string, Collection> $byWeek */
        $byWeek = [];
        foreach ($weeks as $key) {
            $byWeek[$key] = collect();
        }

        foreach ($entries as $entry) {
            $analysis = $entry->analysis;
            if (!$analysis) {
                continue;
            }
            // Same reliability filter as WeeklyReflectionController -- an
            // analysis the model itself flagged low-confidence on doesn't
            // get to skew a week's read any more than it would there.
            if ($analysis->extraction_confidence !== null && $analysis->extraction_confidence < 0.4) {
                continue;
            }

            // Bucket by the ENTRY's created_at, not the analysis row's --
            // extraction runs async and can land in a different wall-clock
            // moment than the entry itself.
            $key = $entry->created_at->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            if (!isset($byWeek[$key])) {
                continue; // defensive -- shouldn't happen given the whereBetween scope above
            }
            $byWeek[$key]->push($analysis);
        }

        $weekMeta = [];
        foreach ($weeks as $key) {
            $weekMeta[] = [
                'key'         => $key,
                'label'       => Carbon::parse($key)->format('M j'),
                'entry_count' => $byWeek[$key]->count(),
            ];
        }

        $result = [
            'weeks'            => $weekMeta,
            'focus_month'      => $endMonth->format('Y-m'),
            'window_months'    => $windowMonths,
            'is_current_month' => $endMonth->isSameMonth(now()),
        ];

        foreach (self::FIELDS as $prefix => $spec) {
            $result["{$prefix}_trends"] = $this->fieldTrends($weeks, $byWeek, $spec['column'], $spec['labelKey'], $spec['weightKey']);
        }

        return $result;
    }

    /**
     * @param array<int, string>              $weeks  week keys (Monday dates), oldest first
     * @param array<string, Collection>       $byWeek EntryAnalysis rows per week key
     * @return array<int, array{label: string, rates: array<int, ?float>, direction: string, delta: ?float}>
     */
    private function fieldTrends(array $weeks, array $byWeek, string $column, ?string $labelKey, ?string $weightKey): array
    {
        // One shared correction lookup across the whole window, rather
        // than one query per week bucket.
        $allAnalyses = collect($byWeek)->flatten(1);
        $analysisIds = $allAnalyses->pluck('id')->filter()->values()->all();

        $correctionMap = [];
        if (!empty($analysisIds)) {
            AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)
                ->where('field', $column)
                ->get()
                ->each(function ($c) use (&$correctionMap) {
                    $correctionMap["{$c->entry_analysis_id}:{$c->original_value}"] = $c;
                });
        }

        // presenceByWeek[weekKey][label] = how many analyses that week
        // carried the label, post-corrections. weightedTotal[label] = sum
        // across the WHOLE window, used only to decide which labels make
        // the cut -- never used for the per-week rates themselves, which
        // are always a plain presence fraction.
        $presenceByWeek = [];
        $weightedTotal  = [];
        foreach ($weeks as $key) {
            $presenceByWeek[$key] = [];
        }

        foreach ($byWeek as $weekKey => $analyses) {
            foreach ($analyses as $analysis) {
                $list = $analysis->{$column} ?? [];
                $seenThisEntry = [];

                foreach ($list as $item) {
                    $label = $labelKey ? ($item[$labelKey] ?? null) : $item;
                    if (!$label) {
                        continue;
                    }

                    $correction = $correctionMap["{$analysis->id}:{$label}"] ?? null;
                    if ($correction) {
                        if ($correction->action === 'removed') {
                            continue; // user said this tag was wrong -- drop it
                        }
                        if ($correction->action === 'corrected' && $correction->corrected_value) {
                            $label = $correction->corrected_value;
                        }
                        // 'confirmed' -> keep original label unchanged
                    }

                    $weight = $weightKey ? ($item[$weightKey] ?? 5) : 1;
                    $weightedTotal[$label] = ($weightedTotal[$label] ?? 0) + $weight;
                    $seenThisEntry[$label] = true;
                }

                foreach (array_keys($seenThisEntry) as $label) {
                    $presenceByWeek[$weekKey][$label] = ($presenceByWeek[$weekKey][$label] ?? 0) + 1;
                }
            }
        }

        if (empty($weightedTotal)) {
            return [];
        }

        arsort($weightedTotal);
        $topLabels = array_slice(array_keys($weightedTotal), 0, self::TOP_LABELS);

        $trends = [];
        foreach ($topLabels as $label) {
            $rates = [];
            foreach ($weeks as $key) {
                $total = $byWeek[$key]->count();
                $rates[] = $total > 0
                    ? round(($presenceByWeek[$key][$label] ?? 0) / $total, 2)
                    : null; // no reliable entries that week -- "no data", not "definitely absent"
            }

            [$direction, $delta] = $this->directionFor($rates, $weeks, $byWeek);

            $trends[] = [
                'label'     => $label,
                'rates'     => $rates,
                'direction' => $direction,
                'delta'     => $delta,
            ];
        }

        return $trends;
    }

    /**
     * Direction/delta compares the two most recent weeks that each have at
     * least MIN_ENTRIES_PER_WEEK_FOR_TREND (1) entry. A lower bar than the
     * original monthly version's implicit "2" -- a week with a single
     * journal entry is a normal week, not a sparse outlier the way a
     * month with one entry would be, and requiring 2 would make
     * "insufficient_data" the common case for a lightly-used week rather
     * than the exception. Deliberately still skips over a genuinely empty
     * week (0 entries) in the middle of the window rather than comparing
     * against it directly.
     *
     * @return array{0: string, 1: ?float}
     */
    private function directionFor(array $rates, array $weeks, array $byWeek): array
    {
        $usable = [];
        foreach ($weeks as $i => $key) {
            if ($byWeek[$key]->count() >= self::MIN_ENTRIES_PER_WEEK_FOR_TREND) {
                $usable[] = $i;
            }
        }

        if (count($usable) < 2) {
            return ['insufficient_data', null];
        }

        $lastIdx = $usable[count($usable) - 1];
        $prevIdx = $usable[count($usable) - 2];

        $last = $rates[$lastIdx] ?? 0.0;
        $prev = $rates[$prevIdx] ?? 0.0;
        $delta = round($last - $prev, 2);

        if ($delta > 0.10) {
            $direction = 'up';
        } elseif ($delta < -0.10) {
            $direction = 'down';
        } else {
            $direction = 'stable';
        }

        return [$direction, $delta];
    }
}
