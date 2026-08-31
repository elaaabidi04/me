<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19, shared-insights round 2 — see docs/therapist-bridge.md §17.
 * `note` on insight_snapshots is client-authored, attached at share-time
 * and frozen with the rest of the row. This is the opposite: a private
 * annotation only the therapist can see or write, added any time after
 * the share exists, same shape as TherapySession::outcome_note. Nullable,
 * lives on the same row rather than a new table since it's a 1:1
 * therapist-per-snapshot note (a snapshot only has one therapist who can
 * see it at all, so there's nothing to key it by beyond the snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->text('therapist_note')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->dropColumn('therapist_note');
        });
    }
};
