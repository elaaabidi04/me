<?php

namespace App\Services;

use App\Models\AnalysisCorrection;

/**
 * Extracted 2026-08-18 from WeeklyReflectionController::tagBreakdown() so
 * the new daily-review breakdown (DailyReviewController) and the existing
 * weekly/monthly aggregations share one implementation instead of drifting
 * — this is the same logic that already computes emotion_breakdown,
 * stressor_breakdown, protective_factor_breakdown, behavior_breakdown,
 * need_breakdown, and thinking_pattern_breakdown for the weekly reflection,
 * unchanged apart from moving file. See docs/dashboard-logic.md §4 for the
 * 'share' field's own history (added the same day, just before this move).
 */
class TagBreakdownService
{
    /**
     * Full sorted breakdown of a tagged-list column across a set of
     * EntryAnalysis rows: every label that appears, ranked by
     * impact-weighted total, each with its own plain presence rate
     * (fraction of rows that mentioned it at all — independent of the
     * weighting used only for sort order) AND its weighted dominance share
     * (2026-08-18 — see the inline comment below where it's computed for
     * why this is a separate number from rate). Capped at $limit so a
     * noisy long tail doesn't dominate a chart meant to be glanced at, not
     * read like a table.
     *
     * Handles three shapes of $column:
     *   - tagged with weight:    [{"category": "work", "impact": 8}, ...]  ($labelKey + $weightKey set)
     *   - tagged without weight: [{"pattern": "rumination"}, ...]         ($labelKey set, $weightKey null)
     *   - flat strings:          ["rest", "connection", ...]              (both null)
     *
     * @param \Illuminate\Support\Collection $analyses EntryAnalysis rows
     * @return array<int, array{label: string, rate: float, share: float}>
     */
    public static function breakdown($analyses, string $column, ?string $labelKey, ?string $weightKey, int $limit = 5): array
    {
        $weighted = [];
        $presence = [];
        $total    = $analyses->count();

        // Batch-load corrections for this column (one query per call, not N+1).
        $analysisIds = $analyses->pluck('id')->filter()->values()->all();
        $correctionMap = [];
        if (!empty($analysisIds)) {
            AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)
                ->where('field', $column)
                ->get()
                ->each(function ($c) use (&$correctionMap) {
                    $correctionMap["{$c->entry_analysis_id}:{$c->original_value}"] = $c;
                });
        }

        foreach ($analyses as $analysis) {
            $list = $analysis->{$column} ?? [];
            $seenThisEntry = [];

            foreach ($list as $item) {
                $label = $labelKey ? ($item[$labelKey] ?? null) : $item;
                if (!$label) {
                    continue;
                }

                // Apply correction if one exists for this (analysis, original label).
                $correction = $correctionMap["{$analysis->id}:{$label}"] ?? null;
                if ($correction) {
                    if ($correction->action === 'removed') {
                        continue; // user said this tag was wrong — drop it
                    }
                    if ($correction->action === 'corrected' && $correction->corrected_value) {
                        $label = $correction->corrected_value; // swap in the corrected label
                    }
                    // 'confirmed' → keep original label unchanged
                }

                $weight = $weightKey ? ($item[$weightKey] ?? 5) : 1;
                $weighted[$label] = ($weighted[$label] ?? 0) + $weight;
                $seenThisEntry[$label] = true;
            }

            foreach (array_keys($seenThisEntry) as $label) {
                $presence[$label] = ($presence[$label] ?? 0) + 1;
            }
        }

        if (empty($weighted)) {
            return [];
        }

        arsort($weighted);

        // 'share' (2026-08-18, real usage feedback: "we need an equation
        // that make the statistics more accurate like showing the dominant
        // emotions with a big percentage and not them having the same
        // value"). 'rate' alone can't do this — it's just presence
        // (fraction of entries that mentioned the label at all, capped at
        // 1.0 per entry regardless of how many times or how intensely).
        // share is each label's weighted total as a fraction of the FULL
        // weighted total across every label that appeared for this column
        // — computed before the top-$limit slice below, so a real long
        // tail is honestly reflected. 'rate' is left unchanged — it still
        // answers "how many entries did this show up in," a genuinely
        // different question from "how much did this dominate."
        $totalWeighted = array_sum($weighted);

        $breakdown = [];
        foreach (array_slice(array_keys($weighted), 0, $limit) as $label) {
            $breakdown[] = [
                'label' => $label,
                'rate'  => $total > 0 ? round(($presence[$label] ?? 0) / $total, 2) : 0.0,
                'share' => $totalWeighted > 0 ? round($weighted[$label] / $totalWeighted, 3) : 0.0,
            ];
        }

        return $breakdown;
    }
}
