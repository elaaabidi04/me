<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19, therapist-facing detailed emotion diagram — see
 * EmotionPatternService's docblock and docs/therapist-bridge.md §19.
 * Nullable JSON alongside `rows`: only populated when a client shares
 * their weekly emotion_breakdown panel (SharedInsightController::store()),
 * null for every other panel_key/scope — a day-by-trigger-by-time
 * structure doesn't apply to a pie slice of behaviors or a single mood
 * caption, so this deliberately isn't reused as a generic field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->json('daily_pattern')->nullable()->after('rows');
        });
    }

    public function down(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->dropColumn('daily_pattern');
        });
    }
};
