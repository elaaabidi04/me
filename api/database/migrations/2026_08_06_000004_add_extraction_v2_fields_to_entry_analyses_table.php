<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_analyses', function (Blueprint $table) {
            // Extraction schema v2 — see EntryExtractionService::systemPrompt().
            // Everything here is a *fact about this entry* the LLM reports;
            // trends/dominance/volatility across entries are computed in PHP
            // (WeeklyReflectionController), never asked of the model directly.

            // [{"emotion": "anxiety", "intensity": 8}, ...] — closed vocabulary,
            // multiple emotions allowed (replaces forcing a single primary/
            // secondary split). "Dominant emotion" is derived from this array,
            // not a separate LLM field.
            $table->json('emotions')->nullable()->after('sentiment');

            // [{"category": "work", "subcategory": "deadline", "impact": 8}, ...]
            $table->json('stressors')->nullable()->after('emotions');

            // [{"factor": "exercise", "impact": 6}, ...] — the positive
            // counterpart to stressors: what consistently helps.
            $table->json('protective_factors')->nullable()->after('stressors');

            // ["connection", "rest", ...] — flat closed vocabulary, no
            // hierarchy or intensity; presence/absence is the useful signal.
            $table->json('needs')->nullable()->after('protective_factors');

            // [{"behavior": "procrastinated", "impact": 7}, ...] — supersedes
            // the narrower strategy_hint mechanism going forward, though
            // strategy_hint/Strategy is left in place for now since Dashboard's
            // confirm/dismiss UI and CoachService's reference-data lookup both
            // depend on it. See docs/ai-behavior.md §6 for the open question.
            $table->json('behaviors')->nullable()->after('needs');

            // EXPERIMENTAL field — validated leniently, allowed to fail
            // without affecting any other field on this row. Evidence quote
            // is kept (not discarded) so the dashboard can show the line that
            // triggered the tag instead of presenting it as an opaque verdict.
            // [{"pattern": "rumination", "evidence": "I kept thinking about it all day."}]
            $table->json('thinking_patterns')->nullable()->after('behaviors');

            // true | false | null(not mentioned) — only populated when the
            // entry itself narrates a same-day emotional arc. Sparse by
            // design; cross-entry recovery speed is a PHP computation, not this.
            $table->boolean('recovered')->nullable()->after('thinking_patterns');

            // One overall confidence per entry (not per-field — see
            // docs/ai-behavior.md §6 for why), lets aggregates filter out
            // ambiguous/sarcastic entries. Named "extraction_confidence" to
            // avoid colliding with the existing `confidence` column, which is
            // reserved for the still-unbuilt classifier (LLM call #1) and
            // means something different (confidence in a mode/distress
            // recommendation, not confidence in an emotion/sentiment read).
            $table->decimal('extraction_confidence', 4, 3)->nullable()->after('recovered');

            // Field-level validation audit trail: which top-level keys (if
            // any) failed validation on this row and why, e.g.
            // {"thinking_patterns": "value 'overthinking' not in enum"}.
            // Populated even when the rest of the row saved successfully —
            // this is what makes field-level validation debuggable instead
            // of just silently dropping data.
            $table->json('extraction_issues')->nullable()->after('extraction_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('entry_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'emotions',
                'stressors',
                'protective_factors',
                'needs',
                'behaviors',
                'thinking_patterns',
                'recovered',
                'extraction_confidence',
                'extraction_issues',
            ]);
        });
    }
};
