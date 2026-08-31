<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->enum('valence', ['positive', 'negative', 'neutral'])
                  ->nullable()
                  ->default(null)
                  ->after('trigger_status');

            $table->enum('valence_suggested', ['positive', 'negative'])
                  ->nullable()
                  ->default(null)
                  ->after('valence');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['valence', 'valence_suggested']);
        });
    }
};
