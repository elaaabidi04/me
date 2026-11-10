<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-10: one row per click on "test user" / "test therapist", so /admin
// can show how often the demo gets tried. Only the button and the time: no
// IP, no visitor identity.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_logins', function (Blueprint $table) {
            $table->id();
            $table->string('role', 16);          // client | therapist
            $table->boolean('is_new');           // true = first click from that browser (a new copy)
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_logins');
    }
};
