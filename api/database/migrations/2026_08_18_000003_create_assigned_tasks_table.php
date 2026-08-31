<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-18, "i want to add tasks if he wants to give them to his
 * patient and the patient cross them" -- a therapist assigns a small
 * task (title + optional instructions + optional due date) to a
 * currently-linked client; the client checks it off on their side. See
 * docs/therapist-bridge.md for the full design.
 *
 * Named AssignedTask/assigned_tasks rather than the bare "Task" to stay
 * unambiguous next to this app's other per-user rows (DailyIntent,
 * DailyReview, etc.) and because "task" alone reads too generically for
 * something that specifically crosses the therapist->client boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assigned_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapist_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->text('instructions')->nullable();

            // Plain 'Y-m-d' string, deliberately uncast on the model (see
            // DailyReview's docblock, 2026-08-18, for the real bug that
            // taught this lesson) -- not that a cast would be unsafe here
            // specifically (nothing does a raw where() search on due_date),
            // but there's no upside to it either, so it stays plain on
            // principle.
            $table->date('due_date')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'completed_at']);
            $table->index(['therapist_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assigned_tasks');
    }
};
