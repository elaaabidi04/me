<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-18, "should we add calendar for it to check when is the
 * session and before a day a notification to check the in between
 * progress" -- a therapist schedules a session with a currently-linked
 * client; either side can see when the next one is. See
 * TherapySession's docblock and docs/therapist-bridge.md.
 *
 * Named therapy_sessions, not sessions -- this app's SESSION_DRIVER is
 * 'database' (see .env), which already owns a real `sessions` table for
 * Laravel's own session storage. Colliding with that would have been a
 * quiet, nasty bug (any of that table's writes competing with Laravel's
 * own session handler), confirmed absent before picking this name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapist_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $table->dateTime('scheduled_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'scheduled_at']);
            $table->index(['therapist_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapy_sessions');
    }
};
