<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_analysis_id')->constrained('entry_analyses')->cascadeOnDelete();
            $table->string('field');           // emotions | stressors | protective_factors | needs | behaviors | thinking_patterns
            $table->string('original_value');  // the label the AI extracted, e.g. "anxiety"
            $table->string('action');          // confirmed | removed | corrected
            $table->string('corrected_value')->nullable(); // only when action=corrected
            $table->timestamps();

            $table->unique(['entry_analysis_id', 'field', 'original_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_corrections');
    }
};
