<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canon_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_entry_id')->nullable()->constrained('entries')->cascadeOnDelete();
            $table->text('description');
            // suggested: extraction flagged this, not yet acted on.
            // confirmed: user said "yes, remember this" -- the coach can now
            //   retrieve it (once it has an embedding, see below).
            // dismissed: user said no at the suggestion stage -- never became
            //   a real memory.
            // forgotten: was confirmed and in active use, user later removed
            //   it from "what me remembers" -- kept as a row (not deleted)
            //   for the same non-destructive-by-default reason corrections
            //   and strategies use status enums instead of hard deletes.
            $table->enum('status', ['suggested', 'confirmed', 'dismissed', 'forgotten'])->default('suggested');
            // JSON-encoded float vector from NvidiaClient::embed(), computed
            // once at confirm time (see CanonEventController::update()).
            // Nullable: a confirmed event with a failed/pending embedding
            // still shows up in "what me remembers" (visibility doesn't
            // depend on retrieval working), it just isn't retrievable by
            // CoachService until an embedding exists.
            $table->text('embedding')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canon_events');
    }
};
