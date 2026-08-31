<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19: Pre-session briefs — a client-authored "here's what I'd
 * want my therapist to know before we talk" note. Mirrors InsightSnapshot
 * in structure (same polymorphic Share gate — see docs/therapist-bridge.md
 * §8) but the content is free text written by the client, not a frozen
 * copy of a computed dashboard panel. therapy_session_id is nullable so
 * a brief can be written without attaching it to a specific session; when
 * set it points to the upcoming session the client had in mind. nullOnDelete
 * rather than cascade: if a session is rescheduled/cancelled the brief
 * content remains valid, just loses its session anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('therapist_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('therapy_session_id')->nullable()->constrained('therapy_sessions')->nullOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['therapist_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_briefs');
    }
};
