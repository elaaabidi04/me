<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 2026-08-18, user-requested: "id like to make the breakdown that the
    // user will unteract with after the daylie discussion like a button
    // save the journal then a notification would u like to see the
    // breakdown and then he sees and choose to correct if the discussion
    // gonna end after 24 hour and the user didn't click save the ai will
    // use the breakdown without the user input automatically." One row
    // per user per calendar day (same shape as daily_intents), tracking
    // three independent, non-destructive timestamps rather than a single
    // enum status column — DailyReview::getStatusAttribute() derives the
    // human status from whichever of these are set. See docs/dashboard-
    // logic.md for the full design writeup and the four scope decisions
    // confirmed with the user before this was built (whole-day scope,
    // non-gating review, in-app banner not push/email, 24h-from-last-
    // activity).
    public function up(): void
    {
        Schema::create('daily_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // Bumped every time an entry is created that day (see
            // EntryController::store()) -- this, not a fixed daily
            // boundary, is what the 24h auto-confirm window counts from.
            $table->timestamp('last_activity_at')->nullable();

            // Set when the user clicks "save the journal." Gates whether
            // the "want to see today's breakdown?" banner ever appears at
            // all -- no save, no banner, just the silent 24h auto-confirm
            // fallback below.
            $table->timestamp('saved_at')->nullable();

            // Set when the user actually opens the breakdown drawer for
            // this day (see DailyReviewController::markReviewed()).
            // Takes precedence over auto_confirmed_at if both end up set
            // (e.g. they look at it after the 24h mark already passed) --
            // a real look always counts as the real review.
            $table->timestamp('reviewed_at')->nullable();

            // Set lazily (no scheduler in this app -- see docs/dashboard-
            // logic.md's known-limitations table on the daily reset being
            // client-side-only for the same reason) whenever any
            // daily-review endpoint notices last_activity_at is >= 24h in
            // the past and neither saved review timestamp is set yet.
            $table->timestamp('auto_confirmed_at')->nullable();

            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reviews');
    }
};
