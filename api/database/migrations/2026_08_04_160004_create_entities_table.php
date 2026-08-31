<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['person', 'place', 'topic']);
            // Tri-state, not boolean: 'unconfirmed' (not yet asked / no opinion) vs
            // 'dismissed' (user said no) must be distinguishable, or the app will
            // keep re-surfacing something the user already rejected.
            $table->enum('trigger_status', ['unconfirmed', 'confirmed', 'dismissed'])
                  ->default('unconfirmed');
            $table->timestamps();
            // Dedup per user — stops the extraction pipeline creating "David" twice.
            $table->unique(['user_id', 'name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
