<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-15: what demo visitors wrote, kept after their 24h copy is
// deleted (App\Support\DemoActivity::archive). The /admin feed reads from
// here plus the live copies. No link back to any account -- the accounts
// are gone; only the visitor label ("visitor abc123") and the text remain.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_archive', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();   // sha256 of sandbox|type|time|body: archiving twice never duplicates
            $table->string('sandbox', 32);
            $table->string('type', 32);
            $table->timestamp('written_at')->index();
            $table->string('author', 120)->nullable();
            $table->string('role', 16)->nullable();
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_archive');
    }
};
