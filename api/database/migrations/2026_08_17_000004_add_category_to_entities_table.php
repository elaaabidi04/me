<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-17, user: "i want to make the threads you've named into
 * categories positive negative work family ext[c]" — a new, separate
 * axis from the existing valence/valence_suggested pair (which stays
 * exactly as-is and keeps powering the weekly triggers panel). category
 * is what the redesigned "threads you've named" card on the dashboard
 * groups by; 'positive'/'negative' are two of its enum values, but the
 * column itself is independent of the valence column — see
 * Entity::CATEGORIES and EntryExtractionService::maybeComputeCategorySuggestions().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('category')->nullable()->after('valence_suggested');
            $table->string('category_suggested')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['category', 'category_suggested']);
        });
    }
};
