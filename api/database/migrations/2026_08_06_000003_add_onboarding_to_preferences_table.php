<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preferences', function (Blueprint $table) {
            $table->json('top_values')->nullable()->after('theme');
            $table->boolean('onboarding_completed')->default(false)->after('top_values');
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table) {
            $table->dropColumn(['top_values', 'onboarding_completed']);
        });
    }
};
