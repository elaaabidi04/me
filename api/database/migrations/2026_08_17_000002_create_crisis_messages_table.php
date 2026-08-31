<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crisis_messages', function (Blueprint $table) {
            $table->id();
            // Anchored on the SAFETY EVENT, not a standalone conversation --
            // one thread per crisis flag (2026-08-17, user: "create a chat
            // between the client and therapist in term of crisis"). This
            // reuses safety_events as the audit trail it already was
            // (CoachService::handleCrisis() has written a row here on every
            // crisis-keyword match since day one) rather than building a
            // parallel messaging system -- see docs/therapist-bridge.md.
            $table->foreignId('safety_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crisis_messages');
    }
};
