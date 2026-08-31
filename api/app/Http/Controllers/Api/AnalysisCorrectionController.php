<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisCorrection;
use App\Models\EntryAnalysis;
use App\Support\ExtractionTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisCorrectionController extends Controller
{
    private const ALLOWED_FIELDS = [
        'emotions', 'stressors', 'protective_factors', 'needs', 'behaviors', 'thinking_patterns',
    ];

    /**
     * POST /api/analysis-corrections
     * Confirm, remove, or correct a single extracted label on an EntryAnalysis.
     * A second submission for the same (analysis, field, original_value) triple
     * updates the existing row rather than inserting a duplicate.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_analysis_id' => ['required', 'integer', 'exists:entry_analyses,id'],
            'field'             => ['required', 'string', 'in:' . implode(',', self::ALLOWED_FIELDS)],
            'original_value'    => ['required', 'string'],
            'action'            => ['required', 'string', 'in:confirmed,removed,corrected'],
            'corrected_value'   => ['nullable', 'string'],
        ]);

        $field          = $validated['field'];
        $originalValue  = $validated['original_value'];
        $action         = $validated['action'];
        $correctedValue = $validated['corrected_value'] ?? null;

        // Ownership check — the analysis must belong to the authenticated user.
        $analysis = EntryAnalysis::whereHas(
            'entry', fn ($q) => $q->where('user_id', $request->user()->id)
        )->findOrFail($validated['entry_analysis_id']);

        // The original_value must actually appear in the extracted JSON array.
        $labelKey       = ExtractionTaxonomy::labelKeyForField($field);
        $existingLabels = collect($analysis->{$field} ?? [])->map(
            fn ($item) => $labelKey ? ($item[$labelKey] ?? null) : $item
        )->filter()->values()->all();

        if (!in_array($originalValue, $existingLabels, true)) {
            return response()->json(
                ['message' => "'{$originalValue}' was not extracted for field '{$field}'"],
                422
            );
        }

        // When action === 'corrected', corrected_value must be a valid enum member.
        if ($action === 'corrected') {
            $allowed = ExtractionTaxonomy::enumForField($field);
            if (!$correctedValue || !in_array($correctedValue, $allowed, true)) {
                return response()->json(
                    ['message' => "corrected_value '{$correctedValue}' is not valid for field '{$field}'"],
                    422
                );
            }
        }

        $correction = AnalysisCorrection::updateOrCreate(
            [
                'entry_analysis_id' => $analysis->id,
                'field'             => $field,
                'original_value'    => $originalValue,
            ],
            [
                'action'          => $action,
                'corrected_value' => $action === 'corrected' ? $correctedValue : null,
            ]
        );

        return response()->json($correction, $correction->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET /api/analysis-corrections/pending-thinking-pattern
     * Returns the most recent extracted thinking pattern (last 30 days) that has
     * not yet been confirmed/removed/corrected by the user, or null if none exists.
     * Iterates in PHP because thinking_patterns is a JSON column, not queryable.
     *
     * Also returns `remaining_after_this`: how many other uncorrected patterns
     * are queued up behind this one. Without this, confirming/dismissing one
     * pattern and immediately being shown a different one — worded almost
     * identically ("does that feel right?") — reads as the same suggestion
     * refusing to go away, when it's actually a queue of distinct patterns
     * being worked through one at a time. Surfacing the count lets the
     * dashboard say "2 more after this one" so it's visibly progressing.
     */
    public function pendingThinkingPattern(Request $request): JsonResponse
    {
        $user  = $request->user();
        $since = now()->subDays(30);

        $entries = $user->entries()
            ->where('created_at', '>=', $since)
            ->with('analysis')
            ->latest()
            ->get();

        $analysisIds = $entries->pluck('analysis.id')->filter()->values()->all();

        // Batch-load all thinking_pattern corrections for these analyses (one query).
        $correctedKeys = AnalysisCorrection::whereIn('entry_analysis_id', $analysisIds)
            ->where('field', 'thinking_patterns')
            ->get()
            ->mapWithKeys(fn ($c) => ["{$c->entry_analysis_id}:{$c->original_value}" => true]);

        $first     = null;
        $remaining = 0;

        foreach ($entries as $entry) {
            $analysis = $entry->analysis;
            if (!$analysis) {
                continue;
            }

            foreach ($analysis->thinking_patterns ?? [] as $item) {
                $label = $item['pattern'] ?? null;
                if (!$label) {
                    continue;
                }

                if ($correctedKeys->has("{$analysis->id}:{$label}")) {
                    continue;
                }

                $remaining++;

                if ($first === null) {
                    $first = [
                        'entry_analysis_id' => $analysis->id,
                        'original_value'    => $label,
                        'evidence'          => $item['evidence'] ?? null,
                        'entry_date'        => $entry->created_at->toDateString(),
                    ];
                }
            }
        }

        if ($first === null) {
            return response()->json(null);
        }

        return response()->json($first + ['remaining_after_this' => $remaining - 1]);
    }
}
