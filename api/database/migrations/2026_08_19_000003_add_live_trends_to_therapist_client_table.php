<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19: Live trend view consent gate — adds live_trends_enabled to
 * the therapist_client pivot. Off by default: a client opts in explicitly
 * from MyTherapist.vue; a therapist cannot enable it themselves. When off,
 * GET /api/monthly/trends/client/{clientId} 403s immediately (checked
 * live on every request — turning it off cuts access with no delay). See
 * docs/therapist-bridge.md §8 for why this is on the pivot rather than a
 * separate Share: this is continuous consent (always-on until revoked),
 * not a one-off snapshot share; the two shapes are genuinely different.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapist_client', function (Blueprint $table) {
            $table->boolean('live_trends_enabled')->default(false)->after('focus_text');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_client', function (Blueprint $table) {
            $table->dropColumn('live_trends_enabled');
        });
    }
};
