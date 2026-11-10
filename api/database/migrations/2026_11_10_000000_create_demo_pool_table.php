<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-10: demo copies built ahead of time (App\Support\DemoSandbox::fillPool),
// so "test user" / "test therapist" log in instantly instead of seeding
// ~700 rows while the visitor waits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_pool', function (Blueprint $table) {
            $table->id();
            $table->string('token', 32)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_pool');
    }
};
