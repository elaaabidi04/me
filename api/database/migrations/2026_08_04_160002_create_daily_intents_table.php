<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('pick', ['engage', 'witness', 'light', 'rest'])->nullable();
            // Counts how many times deep-analysis was requested today — the
            // classifier's `analysis_requests` signal for compulsive-checking detection.
            $table->unsignedInteger('analysis_requests')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_intents');
    }
};
