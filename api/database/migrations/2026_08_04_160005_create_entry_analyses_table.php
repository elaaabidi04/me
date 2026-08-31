<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mood')->nullable();
            $table->string('sentiment')->nullable();
            $table->decimal('rumination_score', 4, 3)->nullable();
            $table->decimal('self_ref_density', 4, 3)->nullable();
            // Classifier output fields (LLM call #1) — needed so the validation
            // note can audit *why* a borderline case did or didn't trigger crisis.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('distress_flag')->default(false);
            $table->decimal('openness', 4, 3)->nullable();
            $table->foreignId('notable_entity_id')->nullable()
                  ->constrained('entities')->nullOnDelete();
            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_analyses');
    }
};
