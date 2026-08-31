<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Therapist-side invite code (2026-08-17, "code invite might be
            // easier" -- the client-to-therapist linking mechanism). Only
            // meaningful for role='therapist' rows, but kept on `users`
            // rather than a separate table: a therapist has exactly one
            // active code at a time, regenerating just overwrites it
            // (invalidating the old one), and a dedicated table/history
            // wasn't asked for. Nullable + unique so a client's redeemed
            // code can be looked up with a plain where('invite_code', ...).
            $table->string('invite_code')->nullable()->unique()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });
    }
};
