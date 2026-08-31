<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19: Session log — adds post-session outcome fields to
 * therapy_sessions. attendance tracks whether the session actually
 * occurred (attended / no_show / cancelled); outcome_note is the
 * therapist's post-session notes, distinct from `notes` which is
 * pre-session scheduling context (see docs/therapist-bridge.md §10).
 * Both nullable: existing rows stay valid until a therapist explicitly
 * logs each past session via PATCH /api/sessions/{session}/log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->string('attendance')->nullable()->after('notes');
            $table->text('outcome_note')->nullable()->after('attendance');
        });
    }

    public function down(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropColumn(['attendance', 'outcome_note']);
        });
    }
};
