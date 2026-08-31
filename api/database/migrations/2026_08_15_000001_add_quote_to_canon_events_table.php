<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canon_events', function (Blueprint $table) {
            // Stage 1 (EntryExtractionService::validateMemoryCandidates())
            // has always captured a supporting quote alongside each
            // candidate and passed it into Stage 2's evaluation payload, but
            // it was previously discarded once a candidate survived both
            // stages -- CanonEvent::create() never wrote it anywhere, and
            // this table never had a column for it. Added 2026-08-15 so a
            // memory suggestion can show the person their own words it's
            // based on, not just the model's paraphrase -- see docs/memory.md
            // for the category-tiered plan this feeds (pattern-category
            // suggestions surface the quote; low-stakes fact/preference/goal
            // ones don't need to). Nullable: every row created before this
            // migration has no quote, which the UI must treat as "fall back
            // to the paraphrase," never as a blank confirmation.
            $table->text('quote')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('canon_events', function (Blueprint $table) {
            $table->dropColumn('quote');
        });
    }
};
