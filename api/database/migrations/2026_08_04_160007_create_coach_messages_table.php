<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->enum('mode', ['engage', 'offer_door', 'witness', 'hold_back', 'crisis']);
            $table->text('body');
            $table->enum('door_choice', ['sit', 'leave'])->nullable();
            // Classifier's one-line reason — logged for validation, never shown to the user.
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_messages');
    }
};
