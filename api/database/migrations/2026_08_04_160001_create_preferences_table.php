<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('tone', ['gentle', 'direct'])->default('gentle');
            $table->unsignedTinyInteger('nudge_level')->default(2);
            $table->enum('language', ['ar', 'darija', 'fr', 'en'])->default('en');
            $table->enum('product_mode', ['solo', 'bridge'])->default('solo');
            $table->string('theme')->default('dusk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferences');
    }
};
