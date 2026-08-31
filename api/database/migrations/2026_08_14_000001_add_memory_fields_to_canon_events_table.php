<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canon_events', function (Blueprint $table) {
            // Generalizes this table from "durable life events only" to the
            // full two-stage memory pipeline (2026-08-14, see
            // docs/ai-behavior.md §11): a row can now hold a stable fact, a
            // preference, a goal, a repeatedly-shown pattern, or (the
            // original case) a notable event. Table/model name deliberately
            // kept as canon_events/CanonEvent -- renaming is pure churn with
            // no functional benefit, and the user-facing surface was always
            // called "memories" (see Memories.vue), never "canon events" --
            // that name only ever lived internally as this app's term for
            // "a durable, user-confirmed thing the coach may recall."
            $table->enum('category', ['fact', 'preference', 'goal', 'pattern', 'event'])
                ->default('event')
                ->after('description');

            // Stage-2 evaluator output (MemoryEvaluatorService) -- see that
            // class's docblock for what each score means and how they
            // combine into the suggest/discard decision. Nullable: rows
            // created before this migration have no score, which the UI
            // treats as "no evaluator opinion recorded," not an error.
            $table->float('importance')->nullable()->after('category');
            $table->float('stability')->nullable()->after('importance');
            $table->float('confidence')->nullable()->after('stability');
            $table->text('reason')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('canon_events', function (Blueprint $table) {
            $table->dropColumn(['category', 'importance', 'stability', 'confidence', 'reason']);
        });
    }
};
