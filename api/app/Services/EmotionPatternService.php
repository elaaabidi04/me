<?php

namespace App\Services;

use App\Models\AnalysisCorrection;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 2026-08-19, therapist-facing detailed emotion diagram (user: "make the
 * insights... detailed by day and what are the triggers and the time when
 * the person feels it") — see docs/therapist-bridge.md §19.
 *
 * A day-by-day, per-item breakdown of one week's emotions for a client:
 * each occurrence carries the trigger it's moment-linked to (same
 * moment-scoped co-occurrence PatternController::topLink() uses — see its
 * docblock) and the hour/time-of-day it was written, derived from
 * Entry::created_at since the extraction schema has no time field of its
 * own. Built specifically for SharedInsightController's emotion_breakdown
 * weekly share, not for any client-facing view — a client already has raw
 * access to their own entries; this exists to hand a *chosen* week of
 * structure to someone who doesn't.
 *
 * Deliberately per-entry detail, not an aggregate like TagBreakdownService
 * or PatternController's topLink() -- this is "what actually happened,
 * day by day," not "what's the established pattern." Reuses the same
 * corrections-resolution and 0.4 reliability floor every other aggregate
 * in this app applies, so a removed/corrected tag never shows up here
 * either.
 */
class EmotionPatternService
{
    private const RELIABILITY_FLOOR = 0.4;

    /**
     * Coarse time-of-day bucket for an hour (0-23). Boundaries are rough
     * on purpose — a therapist scanning this wants "evening" not "19:00."
     * 'night' wraps past midnight (22-23, 0-4).
     */
    public static function timeOfDay(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour <= 11  => 'morning',
            $hour >= 12 && $hour <= 16 => 'afternoon',
            $hour >= 17 && $hour <= 21 => 'evening',
            default                    => 'night',
        };
    }

    /**
     * @return array<int, array{date: string, day_label: string, items: array}>
     *         Empty array when there's no reliable data in the window —
     *         callers should treat that as "nothing to show," not an error.
     */
    public static function weekly(User $client, Carbon $weekStart, Carbon $weekEnd): array
    {
        $entries = $client->entries()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->with('analysis')
            ->orderBy('created_at')
            ->get()
            ->filter(fn ($e) => $e->analysis
                && ($e->analysis->extraction_confidence === null || $e->analysis->extraction_confidence >= self::RELIABILITY_FLOOR))
            ->values();

        if ($entries->isEmpty()) {
            return [];
        }

        // Same batch correction load + resolution as PatternController::
        // buildViews() -- one query, not N+1, and a removed/corrected tag
        // resolved exactly once before anything downstream sees it.
        $analysisIds = $entries->map(fn ($e) => $e->analysis->id)->all();
        $correctionMap = [];
        AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)->get()
            ->each(function ($c) use (&$correctionMap) {
                $correctionMap["{$c->entry_analysis_id}:{$c->field}:{$c->original_value}"] = $c;
            });

        $days = [];

        foreach ($entries as $entry) {
            $analysis  = $entry->analysis;
            $emotions  = self::resolveItems($analysis, 'emotions', 'emotion', $correctionMap);
            $stressors = self::resolveItems($analysis, 'stressors', 'category', $correctionMap);

            if (empty($emotions)) {
                continue;
            }

            // Whole-entry fallback for legacy pre-moment-tagging entries —
            // same rule PatternController::groupByTrigger()/topLink() use:
            // if NO stressor in this entry carries a moment number, every
            // stressor is treated as a candidate trigger for every emotion
            // in the entry, rather than reporting "no trigger" for data
            // that simply predates moment-tagging (2026-08-12).
            $stressorsHaveMoments = collect($stressors)->contains(fn ($s) => $s['moment'] !== null);

            $date = $entry->created_at->toDateString();
            $days[$date] ??= [
                'date'      => $date,
                'day_label' => $entry->created_at->format('D'),
                'items'     => [],
            ];

            $hour = (int) $entry->created_at->format('G');

            foreach ($emotions as $e) {
                $matched = $stressorsHaveMoments
                    ? array_filter($stressors, fn ($s) => $s['moment'] !== null && $s['moment'] === $e['moment'])
                    : $stressors;

                $triggerLabels = array_values(array_unique(array_map(
                    fn ($s) => $s['subcategory'] ?? $s['label'],
                    $matched
                )));

                $days[$date]['items'][] = [
                    'emotion'     => $e['label'],
                    'intensity'   => $e['intensity'],
                    'trigger'     => $triggerLabels ? implode(' & ', $triggerLabels) : null,
                    'hour'        => $hour,
                    'time_of_day' => self::timeOfDay($hour),
                ];
            }
        }

        $result = array_values($days);
        usort($result, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * Corrected {label, subcategory, moment, intensity} rows for one
     * column of one analysis -- identical resolution order to
     * PatternController::buildViews() (removed drops it, corrected swaps
     * the label), just returning a flatter shape since this doesn't need
     * buildViews()'s drill-through extras.
     */
    private static function resolveItems($analysis, string $column, string $labelKey, array $correctionMap): array
    {
        $out = [];
        foreach ($analysis->{$column} ?? [] as $item) {
            $originalLabel = $item[$labelKey] ?? null;
            if (!$originalLabel) {
                continue;
            }

            $label = $originalLabel;
            $correction = $correctionMap["{$analysis->id}:{$column}:{$originalLabel}"] ?? null;
            if ($correction) {
                if ($correction->action === 'removed') {
                    continue;
                }
                if ($correction->action === 'corrected' && $correction->corrected_value) {
                    $label = $correction->corrected_value;
                }
            }

            $out[] = [
                'label'       => $label,
                'subcategory' => $item['subcategory'] ?? null,
                'moment'      => $item['moment'] ?? null,
                'intensity'   => $item['intensity'] ?? null,
            ];
        }

        return $out;
    }
}
