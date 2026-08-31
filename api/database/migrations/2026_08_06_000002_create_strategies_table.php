<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->foreignId('source_entry_id')->nullable()->constrained('entries')->cascadeOnDelete();
            $table->enum('status', ['suggested', 'confirmed', 'dismissed'])->default('suggested');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
