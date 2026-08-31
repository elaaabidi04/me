<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-17, user: "we need to automate the procedure for every therapist
 * we only need one conversation just when the crisis happen" -- crisis
 * chat moves from one thread PER SafetyEvent to one persistent thread PER
 * therapist_client link, with SafetyEvents surfaced as inline "flags"
 * inside that single thread instead of separate rooms. See
 * docs/therapist-bridge.md for the full redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crisis_messages', function (Blueprint $table) {
            $table->foreignId('therapist_client_id')->nullable()->after('id')
                ->constrained('therapist_client')->cascadeOnDelete();
        });

        // Backfill: every existing message was created under the old
        // one-thread-per-SafetyEvent design, so its conversation is
        // whichever therapist_client link is currently active for that
        // event's user.
        DB::statement('
            UPDATE crisis_messages
            SET therapist_client_id = (
                SELECT tc.id FROM therapist_client tc
                JOIN safety_events se ON se.id = crisis_messages.safety_event_id
                WHERE tc.client_id = se.user_id
            )
        ');

        // safety_event_id is now optional context (which flag, if any, a
        // message is a direct reply about), not the thread's identity --
        // was NOT NULL originally.
        Schema::table('crisis_messages', function (Blueprint $table) {
            $table->foreignId('safety_event_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crisis_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('therapist_client_id');
        });
    }
};
