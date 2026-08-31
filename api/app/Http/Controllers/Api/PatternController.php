<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisCorrection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatternController extends Controller
{
    // A trigger needs to have shown up in at least this many reliable
    // entries before any chain is computed for it — three coincidences
    // isn't a pattern, but neither is one. Matches the threshold already
    // used elsewhere for "enough signal" (entity valence suggestions).
    private const MIN_TRIGGER_ENTRIES = 3;

    // Within a trigger's entries, a candidate emotion/thinking-pattern/
    // behavior is only reported if it co-occurred at least twice — the
    // "most common of 1" is not a link, it's a single entry.
    private const MIN_LINK_COUNT = 2;

    private const MAX_TRIGGERS = 5;

    // The drill-through URL uses the singular, human name of a chain link
    // ("thinking_pattern") since a link is always one instance; this maps
    // it to the plural JSON column it's actually stored under.
    private const FIELD_COLUMNS = [
        'emotion'          => 'emotions',
        'thinking_pattern' => 'thinking_patterns',
        'behavior'         => 'behaviors',
    ];

    /**
     * GET /api/patterns
     * Co-occurrence, not causation: for each trigger (stressor subcategory,
     * falling back to category) that's shown up often enough, reports which
     * emotion / thinking_pattern / behavior most often appear in the SAME
     * MOMENT — see buildViews()/groupByTrigger() — falling back to "same
     * entry" only for entries written before moment-tagging existed
     * (2026-08-12). Deliberately all-time, not windowed to the current week
     * like WeeklyReflectionController — a week rarely has enough repetition
     * of any one trigger for this to mean anything. Purely a re-aggregation
     * of already-extracted per-entry tags; nothing here is asked of the
     * model, same extraction-vs-inference split as everything else computed
     * in this app. See docs/ai-behavior.md §7 "Pattern chains".
     */
    public function index(Request $request): JsonResponse
    {
        $views = $this->buildViews($request->user());

        if (empty($views)) {
            return response()->json(['chains' => [], 'total_reliable_entries' => 0]);
        }

        $byTrigger = $this->groupByTrigger($views);

        $chains = [];
        foreach ($byTrigger as $trigger => $group) {
            if (count($group) < self::MIN_TRIGGER_ENTRIES) {
                continue;
            }

            $chains[] = [
                'trigger'          => $trigger,
                'trigger_count'    => count($group),
                'emotion'          => $this->topLink($group, 'emotions'),
                'thinking_pattern' => $this->topLink($group, 'thinking_patterns'),
                'behavior'         => $this->topLink($group, 'behaviors'),
            ];
        }

        // Most-established pattern (most entries behind it) first.
        usort($chains, fn ($a, $b) => $b['trigger_count'] <=> $a['trigger_count']);

        return response()->json([
            'chains'                 => array_slice($chains, 0, self::MAX_TRIGGERS),
            'total_reliable_entries' => count($views),
        ]);
    }

    /**
     * GET /api/patterns/{trigger}
     * GET /api/patterns/{trigger}/{field}/{value}
     * The "show me the entries" behind a chain link — lets you check a
     * pattern against the actual writing instead of trusting the aggregate.
     * With no field/value: every reliable entry behind the trigger. With
     * them: narrowed to entries where that specific link's label was
     * present in the SAME MOMENT as the trigger (post-correction — same
     * resolution and same moment-scoping index()/topLink() use; see
     * groupByTrigger()), each carrying the matching item(s) so the frontend
     * can show real detail — for thinking_pattern that's the literal
     * evidence quote the model flagged, the same field EntryAnalysis.vue
     * grounds text highlights in. Each entry also reports `moment_linked`
     * so the frontend can show when a match is moment-verified versus a
     * legacy whole-entry fallback (see topLink()'s docblock).
     */
    public function show(Request $request, string $trigger, ?string $field = null, ?string $value = null): JsonResponse
    {
        if ($field !== null && !isset(self::FIELD_COLUMNS[$field])) {
            return response()->json([
                'message' => 'unknown field — expected emotion, thinking_pattern, or behavior',
            ], 404);
        }

        $column = $field ? self::FIELD_COLUMNS[$field] : null;

        $views = $this->buildViews($request->user());
        $group = $this->groupByTrigger($views)[$trigger] ?? [];

        if (count($group) < self::MIN_TRIGGER_ENTRIES) {
            return response()->json([
                'trigger' => $trigger, 'field' => $field, 'value' => $value,
                'count' => 0, 'entries' => [],
            ]);
        }

        $entries = [];
        foreach ($group as $occurrence) {
            $v              = $occurrence['view'];
            $triggerMoments = $occurrence['moments'];
            $momentLinked   = false;

            $items = null;
            if ($column) {
                $candidates = $v[$column];
                if (!empty($triggerMoments)) {
                    $candidates   = array_values(array_filter($candidates, fn ($it) => in_array($it['moment'], $triggerMoments, true)));
                    $momentLinked = true;
                }
                // Empty $triggerMoments (no moment data on either side —
                // legacy entry) falls back to every $column item in the
                // entry, same as this endpoint behaved before moment-tagging.

                $items = array_values(array_filter($candidates, fn ($it) => $it['label'] === $value));
                if (empty($items)) {
                    continue; // this entry is part of the trigger but doesn't carry this specific link (in this moment, if known)
                }
            }

            $entries[] = [
                'id'            => $v['entry_id'],
                'created_at'    => $v['created_at'],
                'body'          => $v['body'],
                'items'         => $items,
                'moment_linked' => $column ? $momentLinked : null,
            ];
        }

        // Newest first — feels more natural for "show me the entries" than
        // the oldest-first order buildViews() happens to produce.
        usort($entries, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return response()->json([
            'trigger' => $trigger, 'field' => $field, 'value' => $value,
            'count'   => count($entries), 'entries' => $entries,
        ]);
    }

    /**
     * One reliable, corrected "view" per entry — labels are resolved
     * through any correction ('removed' drops the tag, 'corrected' swaps
     * in the replacement label) exactly once here, so grouping, counting,
     * and the drill-through never have to know corrections exist. Each row
     * also keeps the original label and a handful of per-item extras
     * (evidence quote, intensity/impact) so the drill-through has enough
     * to show real detail without a second query per entry.
     */
    private function buildViews($user): array
    {
        $entries = $user->entries()
            ->with('analysis')
            ->get()
            // No analysis at all (still processing, or a vault entry that
            // was never sent out) — and the same 0.4 reliability floor used
            // everywhere else this app aggregates extracted tags.
            ->filter(fn ($e) => $e->analysis
                && ($e->analysis->extraction_confidence === null || $e->analysis->extraction_confidence >= 0.4))
            ->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $analysisIds = $entries->map(fn ($e) => $e->analysis->id)->all();

        // Batch-load every correction once (not per-tag, not per-entry) —
        // same N+1 avoidance as WeeklyReflectionController::tagBreakdown().
        $correctionMap = [];
        AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)
            ->get()
            ->each(function ($c) use (&$correctionMap) {
                $correctionMap["{$c->entry_analysis_id}:{$c->field}:{$c->original_value}"] = $c;
            });

        // column => [label key, extra per-item keys worth carrying through
        // to the drill-through response]. 'moment' is carried on every
        // field (added 2026-08-12) so groupByTrigger()/topLink() can scope
        // co-occurrence to the same beat of the entry instead of "anywhere
        // in this entry" — see those methods' docblocks.
        $fieldSpec = [
            'stressors'         => ['key' => 'category', 'extra' => ['subcategory', 'impact', 'moment']],
            'emotions'          => ['key' => 'emotion',   'extra' => ['intensity', 'moment']],
            'thinking_patterns' => ['key' => 'pattern',   'extra' => ['evidence', 'moment']],
            'behaviors'         => ['key' => 'behavior',  'extra' => ['impact', 'moment']],
        ];

        $views = [];
        foreach ($entries as $e) {
            $a = $e->analysis;
            $view = [
                'entry_id'   => $e->id,
                'created_at' => $e->created_at->toIso8601String(),
                'body'       => $e->body,
                'stressors'         => [],
                'emotions'          => [],
                'thinking_patterns' => [],
                'behaviors'         => [],
            ];

            foreach ($fieldSpec as $column => $spec) {
                foreach ($a->{$column} ?? [] as $item) {
                    $originalLabel = $item[$spec['key']] ?? null;
                    if (!$originalLabel) {
                        continue;
                    }

                    $label = $originalLabel;
                    $correction = $correctionMap["{$a->id}:{$column}:{$originalLabel}"] ?? null;
                    if ($correction) {
                        if ($correction->action === 'removed') {
                            continue;
                        }
                        if ($correction->action === 'corrected' && $correction->corrected_value) {
                            $label = $correction->corrected_value;
                        }
                    }

                    $row = ['label' => $label, 'original_label' => $originalLabel];
                    foreach ($spec['extra'] as $extraKey) {
                        $row[$extraKey] = $item[$extraKey] ?? null;
                    }
                    if ($correction) {
                        $row['correction'] = ['action' => $correction->action, 'corrected_value' => $correction->corrected_value];
                    }

                    $view[$column][] = $row;
                }
            }

            $views[] = $view;
        }

        return $views;
    }

    /**
     * Group corrected views by trigger label (subcategory when it's set —
     * closer to "criticism" / "uncertainty" granularity than the broader
     * category). An entry mentioning the same trigger twice only counts
     * once per trigger, but every distinct moment that trigger occurred in
     * is kept (2026-08-12) — see topLink()/show(), which use it to scope
     * co-occurrence to the same beat of the entry rather than "anywhere in
     * this entry." An occurrence's `moments` list is empty when the entry
     * predates moment-tagging (or the model didn't tag this item), which
     * downstream code treats as "no moment data — fall back to whole-entry."
     *
     * @return array<string, array<int, array{view: array, moments: array<int, int>}>>
     */
    private function groupByTrigger(array $views): array
    {
        $byTrigger = [];
        foreach ($views as $v) {
            $momentsByLabel = [];
            foreach ($v['stressors'] as $s) {
                $label = $s['subcategory'] ?? $s['label'];
                if (!$label) {
                    continue;
                }
                $momentsByLabel[$label][] = $s['moment'];
            }

            foreach ($momentsByLabel as $label => $moments) {
                $byTrigger[$label][] = [
                    'view'    => $v,
                    'moments' => array_values(array_unique(array_filter($moments, fn ($m) => $m !== null))),
                ];
            }
        }

        return $byTrigger;
    }

    /**
     * Among a trigger's entries, the most common label in $column — but
     * only if it clears MIN_LINK_COUNT. Returning the "top" pick of a
     * single coincidence would present noise as a pattern; omitting the
     * link entirely (null) is the honest answer when there isn't one yet.
     *
     * Moment-scoped (2026-08-12): for each entry, only items sharing a
     * moment number with the trigger's own occurrence count toward the
     * link — a two-beat entry ("stressed about the deadline, then relieved
     * when a friend called") no longer lets "deadline" get credited with
     * whatever emotion happened to also be in that entry regardless of
     * which beat it belonged to. Entries with no moment data on the
     * trigger side (written before 2026-08-12, or the model didn't tag it)
     * fall back to counting every $column item in the entry, exactly how
     * this worked before moment-tagging existed — old entries don't lose
     * signal, they just don't get the accuracy improvement.
     *
     * @param array<int, array{view: array, moments: array<int, int>}> $group
     * @return array{label: string, count: int, rate: float}|null
     */
    private function topLink(array $group, string $column): ?array
    {
        $counts = [];
        foreach ($group as $occurrence) {
            $items = $occurrence['view'][$column];

            if (!empty($occurrence['moments'])) {
                $items = array_filter($items, fn ($it) => in_array($it['moment'], $occurrence['moments'], true));
            }

            foreach (array_unique(array_column($items, 'label')) as $label) {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return null;
        }

        arsort($counts);
        $topLabel = array_key_first($counts);
        $topCount = $counts[$topLabel];

        if ($topCount < self::MIN_LINK_COUNT) {
            return null;
        }

        return [
            'label' => $topLabel,
            'count' => $topCount,
            'rate'  => round($topCount / count($group), 2),
        ];
    }
}
