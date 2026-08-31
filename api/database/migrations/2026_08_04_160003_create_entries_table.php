<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_intent_id')->nullable()->constrained()->nullOnDelete();
            // Apply the `encrypted` cast on the Entry model, not here — this column
            // just needs to be big enough to hold ciphertext.
            $table->text('body');
            // If true, this entry is never sent to the LLM and never analyzed.
            $table->boolean('in_vault')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
