<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntryAnalysis;
use App\Models\Strategy;
use App\Models\User;
use App\Models\WeeklyReflection;
use App\Services\TagBreakdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class WeeklyReflectionController extends Controller
{
    /**
     * GET /api/reflections/current
     * Returns the current week's reflection, generating it on first access
     * once the week has ended (Sunday EOD).
     */
    public function current(Request $request): JsonResponse
    {
        $devBypass = config('app.reflection_dev_bypass', false);

        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $unlockAt  = now()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        // Plain where('week_start', $weekStart) would compare against the raw
        // string, but the model's `date` cast stores this with a time suffix
        // ('2026-08-03 00:00:00') — an exact-string match on the bare date
        // never hits, so firstOrCreate kept re-attempting the INSERT and
        // tripping the unique constraint under any near-simultaneous request.
        // whereDate() compares via SQL DATE(), which is tolerant of that.
        $reflection = WeeklyReflection::where('user_id', $request->user()->id)
            ->whereDate('week_start', $weekStart)
            ->first();

        if (!$reflection) {
            try {
                $reflection = WeeklyReflection::create([
                    'user_id'    => $request->user()->id,
                    'week_start' => $weekStart,
                    'status'     => 'sealed',
                    'unlock_at'  => $unlockAt,
                    'content'    => null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Race: another request created the row between our SELECT
                // and this INSERT. Fetch what's there instead of 500-ing.
                $reflection = WeeklyReflection::where('user_id', $request->user()->id)
                    ->whereDate('week_start', $weekStart)
                    ->firstOrFail();
            }
        }

        $isLocked = now()->lt($reflection->unlock_at)
            && !in_array($reflection->status, ['ready', 'opened'])
            && !$devBypass;

        if ($isLocked) {
            return response()->json(
                ['sealed_until' => $reflection->unlock_at->toISOString()],
                423
            );
        }

        if ($devBypass) {
            Log::warning('WeeklyReflectionController: REFLECTION_DEV_BYPASS is active');
        }

        // With the bypass on, always regenerate instead of only when
        // status='sealed' — otherwise the first view after seeding/adding
        // data gets cached as 'opened' and never recalculates, so newly
        // added entries/entities silently never show up. In production
        // (bypass off) this still only computes once per week, which is
        // the intended "sealed" ritual.
        if ($reflection->status === 'sealed' || $devBypass) {
            $content = $this->generateContent($request->user(), $weekStart);
            $reflection->update([
                'status'  => $reflection->status === 'sealed' ? 'ready' : $reflection->status,
                'content' => json_encode($content),
            ]);
        }

        if ($reflection->status === 'ready') {
            $reflection->update(['status' => 'opened']);
        }

        return response()->json($reflection->fresh());
    }

    /**
     * Build the weekly reflection data array from the user's entries.
     */
    private function generateContent(User $user, string $weekStart): array
    {
        $weekEnd      = Carbon::parse($weekStart)->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $prevWeekStart = Carbon::parse($weekStart)->subWeek();

        // Current week entries
        $weekEntries = $user->entries()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->with('analysis')
            ->get();

        // Previous week entries
        $prevEntries = $user->entries()
            ->whereBetween('created_at', [
                $prevWeekStart,
                Carbon::parse($weekStart)->subSecond(),
            ])
            ->with('analysis')
            ->get();

        $analyses     = $weekEntries->pluck('analysis')->filter();
        $prevAnalyses = $prevEntries->pluck('analysis')->filter();

        // Extraction schema v2 aggregates are computed only from entries the
        // model itself was confident about — an analysis with no confidence
        // score (older rows, pre-v2) is still included, but a wildly
        // ambiguous/sarcastic entry the model flagged low-confidence on is
        // excluded so it doesn't skew a whole week's "top stressor". This
        // filter only applies to the new fields below; mood_distribution,
        // dominant_mood, trend, and the entity triggers above are unchanged.
        $reliableAnalyses = $analyses->filter(
            fn ($a) => $a->extraction_confidence === null || $a->extraction_confidence >= 0.4
        );

        // mood_distribution
        $moodDistribution = $analyses
            ->groupBy('sentiment')
            ->map->count()
            ->toArray();

        // dominant_mood
        $dominantMood = $analyses
            ->pluck('mood')
            ->filter()
            ->countBy()
            ->sortByDesc(fn ($v, $k) => $v)
            ->keys()
            ->first();

        // trend
        $weekTotal    = $analyses->count();
        $prevTotal    = $prevAnalyses->count();
        $weekPositive = $analyses->where('sentiment', 'positive')->count();
        $prevPositive = $prevAnalyses->where('sentiment', 'positive')->count();

        $weekPositiveRate = $weekTotal > 0 ? $weekPositive / $weekTotal : 0;
        $prevPositiveRate = $prevTotal  > 0 ? $prevPositive / $prevTotal  : 0;

        if ($weekTotal < 2 || $prevTotal < 2) {
            $trend = 'insufficient_data';
        } elseif ($weekPositiveRate > $prevPositiveRate + 0.10) {
            $trend = 'up';
        } elseif ($weekPositiveRate < $prevPositiveRate - 0.10) {
            $trend = 'down';
        } else {
            $trend = 'stable';
        }

        // top_positive_trigger
        $topPositiveTrigger = $user->entities()
            ->where('trigger_status', 'confirmed')
            ->where('valence', 'positive')
            ->withCount(['entries' => fn ($q) => $q->whereBetween('entries.created_at', [$weekStart, $weekEnd])])
            ->orderByDesc('entries_count')
            ->first()
            ?->name;

        // top_negative_trigger
        $topNegativeTrigger = $user->entities()
            ->where('trigger_status', 'confirmed')
            ->where('valence', 'negative')
            ->withCount(['entries' => fn ($q) => $q->whereBetween('entries.created_at', [$weekStart, $weekEnd])])
            ->orderByDesc('entries_count')
            ->first()
            ?->name;

        // Extraction schema v2 — each *_breakdown is a list of
        // {"label": ..., "rate": 0.0-1.0, "share": 0.0-1.0}, sorted
        // highest-weighted first, capped at 5. "rate" is the plain
        // presence rate (what fraction of this week's reliable entries
        // mentioned it at all) — lets the frontend say "appears in 41% of
        // entries" / "a few times" instead of a raw occurrence count.
        // "share" (added 2026-08-18) is that same label's weighted total as
        // a fraction of the FULL weighted total across every label the
        // field produced this week — this is what actually differentiates
        // a truly dominant label from one that merely showed up equally
        // often; see tagBreakdown()'s docblock for why rate alone couldn't
        // do this. Full breakdowns (not just a single "top" value) are
        // what let the dashboard draw an actual chart instead of one stat.
        $emotionBreakdown          = TagBreakdownService::breakdown($reliableAnalyses, 'emotions', 'emotion', 'intensity');
        $stressorBreakdown         = TagBreakdownService::breakdown($reliableAnalyses, 'stressors', 'category', 'impact');
        $protectiveFactorBreakdown = TagBreakdownService::breakdown($reliableAnalyses, 'protective_factors', 'factor', 'impact');
        $behaviorBreakdown         = TagBreakdownService::breakdown($reliableAnalyses, 'behaviors', 'behavior', 'impact');
        // 'need' labelKey since 2026-08-12 — needs moved off the flat-string
        // shape to {"need": ..., "moment": ...} like every other field.
        $needBreakdown             = TagBreakdownService::breakdown($reliableAnalyses, 'needs', 'need', null);
        // Experimental field — expect this to come back empty often, see
        // docs/ai-behavior.md §7.
        $thinkingPatternBreakdown  = TagBreakdownService::breakdown($reliableAnalyses, 'thinking_patterns', 'pattern', null);

        // insights
        $insights = [];
        $entitiesWithValence = $user->entities()
            ->whereNotNull('valence')
            ->limit(5)
            ->get();

        foreach ($entitiesWithValence as $entity) {
            if (count($insights) >= 2) {
                break;
            }

            $entityEntries = $entity->entries()->with('analysis')->get();

            if ($entityEntries->count() < 3) {
                continue;
            }

            $entityAnalyses      = $entityEntries->pluck('analysis')->filter();
            $entityTotal         = $entityAnalyses->count();

            if ($entityTotal === 0) {
                continue;
            }

            $entityPositiveCount = $entityAnalyses->where('sentiment', 'positive')->count();
            $positiveRate        = $entityPositiveCount / $entityTotal;

            if ($positiveRate >= 0.6) {
                $insights[] = "days mentioning '{$entity->name}' tend to run higher in mood";
            } elseif ($positiveRate <= 0.3) {
                $insights[] = "days mentioning '{$entity->name}' tend to run lower in mood";
            }
        }

        return [
            'mood_distribution'     => $moodDistribution,
            'dominant_mood'         => $dominantMood,
            'trend'                 => $trend,
            'top_positive_trigger'  => $topPositiveTrigger,
            'top_negative_trigger'  => $topNegativeTrigger,
            'insights'              => $insights,
            'entry_count'           => $analyses->count(),
            // Extraction schema v2 — see docs/ai-behavior.md §6. Full sorted
            // breakdowns, not a single "top" value — the frontend renders
            // these as actual charts (a ring for mood_distribution, soft bar
            // lists for the rest) and applies its own warm, human phrasing
            // on top of these raw category labels (see Dashboard.vue).
            'emotion_breakdown'          => $emotionBreakdown,
            'stressor_breakdown'         => $stressorBreakdown,
            'protective_factor_breakdown'=> $protectiveFactorBreakdown,
            'behavior_breakdown'         => $behaviorBreakdown,
            'need_breakdown'             => $needBreakdown,
            'thinking_pattern_breakdown' => $thinkingPatternBreakdown,
        ];
    }

    // tagBreakdown() moved to App\Services\TagBreakdownService on 2026-08-18
    // so the new daily-review breakdown could reuse it without duplicating
    // the correction-resolution + share/rate math — see that class's
    // docblock. Every call site above now reads TagBreakdownService::breakdown(...).

    /**
     * GET /api/reflections/live
     * Live (ungated) stats for the current week — always returns real data
     * from this week's entries regardless of the Sunday unlock schedule.
     * The weekly reflection ritual (current endpoint + sealed state) is
     * intentional UX; this endpoint is what powers the always-visible charts.
     */
    public function live(Request $request): JsonResponse
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $content   = $this->generateContent($request->user(), $weekStart);
        return response()->json($content);
    }

    /**
     * GET /api/reflections/emotion-detail/{emotion}
     * Returns this-week's stats for one emotion: count, avg intensity,
     * top correlated stressors, behaviors, and thinking patterns.
     * Scoped to the current user's entries from this week (Mon–now).
     */
    public function emotionDetail(Request $request, string $emotion): JsonResponse
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);

        $analyses = EntryAnalysis::whereHas('entry', fn($q) =>
            $q->where('user_id', $request->user()->id)
              ->where('created_at', '>=', $startOfWeek)
        )->get(['emotions', 'stressors', 'behaviors', 'thinking_patterns']);

        $count          = 0;
        $intensitySum   = 0;
        $intensityN     = 0;
        $stressorCounts = [];
        $behaviorCounts = [];
        $patternCounts  = [];

        foreach ($analyses as $analysis) {
            // Stored schema: emotions=[{emotion, intensity}], behaviors=[{behavior, impact}],
            // thinking_patterns=[{pattern, evidence}] — keys match EntryExtractionService output.
            $matched = null;
            foreach ($analysis->emotions ?? [] as $em) {
                if (($em['emotion'] ?? '') === $emotion) {
                    $matched = $em;
                    break;
                }
            }
            if (!$matched) continue;

            $count++;

            $rawIntensity = $matched['intensity'] ?? null;
            if (is_numeric($rawIntensity)) {
                // intensity stored as 1-10 int from validateEmotions()
                $intensitySum += (float) $rawIntensity;
                $intensityN++;
            }

            foreach ($analysis->stressors ?? [] as $s) {
                $cat = $s['category'] ?? null;
                if ($cat) $stressorCounts[$cat] = ($stressorCounts[$cat] ?? 0) + 1;
            }
            foreach ($analysis->behaviors ?? [] as $b) {
                $bLabel = $b['behavior'] ?? null;
                if ($bLabel) $behaviorCounts[$bLabel] = ($behaviorCounts[$bLabel] ?? 0) + 1;
            }
            foreach ($analysis->thinking_patterns ?? [] as $tp) {
                $pLabel = $tp['pattern'] ?? null;
                if ($pLabel) $patternCounts[$pLabel] = ($patternCounts[$pLabel] ?? 0) + 1;
            }
        }

        arsort($stressorCounts);
        arsort($behaviorCounts);
        arsort($patternCounts);

        return response()->json([
            'emotion'       => $emotion,
            'count'         => $count,
            'avg_intensity' => $intensityN > 0 ? round($intensitySum / $intensityN, 1) : null,
            'top_stressors' => array_slice(array_keys($stressorCounts), 0, 3),
            'top_behaviors' => array_slice(array_keys($behaviorCounts), 0, 3),
            'top_patterns'  => array_slice(array_keys($patternCounts),  0, 3),
        ]);
    }
}
