<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DailyReview extends Model
{
    // 24h-from-last-activity, not a fixed daily boundary -- confirmed with
    // the user over the daily-reset-window alternative, since a day's
    // "discussion" can run late and the fixed 23:30-00:15 reset window is
    // itself only client-side (see docs/dashboard-logic.md #16).
    private const AUTO_CONFIRM_AFTER_HOURS = 24;

    protected $fillable = [
        'user_id',
        'date',
        'last_activity_at',
        'saved_at',
        'reviewed_at',
        'auto_confirmed_at',
    ];

    // 2026-08-18, real bug found from actual usage: 'date' was originally
    // cast to Eloquent's 'date' type. That cast only affects how the value
    // looks in PHP (a Carbon instance) and in JSON -- it does NOT change
    // how Eloquent formats the value for SQL storage, which always goes
    // through $dateFormat ('Y-m-d H:i:s' by default) regardless of cast
    // type. So a row created via fill()/save() (e.g. updateOrCreate()'s
    // create path) got 'date' written as '2026-08-18 00:00:00', while every
    // updateOrCreate() SEARCH used a plain 'Y-m-d' string (raw array
    // conditions never go through casts) -- the search could never match
    // the row the write had actually produced, so every second write for
    // the same day tried to INSERT a "new" row and hit the unique
    // (user_id, date) constraint. Confirmed via storage/logs/laravel.log:
    // a real UNIQUE constraint violation on daily_reviews.user_id/date,
    // with the failed INSERT's own logged SQL showing 'date' as
    // '2026-08-18 00:00:00'. DailyIntent (the same "one row per user per
    // day" shape, already working in production) never cast 'date' at all
    // -- leaving it as a plain string, consistently written and searched
    // the same way. Matching that proven pattern here instead of casting.
    protected function casts(): array
    {
        return [
            'last_activity_at'  => 'datetime',
            'saved_at'          => 'datetime',
            'reviewed_at'       => 'datetime',
            'auto_confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human status, derived rather than stored -- avoids a denormalized
     * enum that could drift from the three timestamps actually driving
     * behavior. 'reviewed' always wins over 'auto_confirmed' even if both
     * end up set (looking at it late still counts as really reviewing it).
     */
    public function getStatusAttribute(): string
    {
        if ($this->reviewed_at) {
            return 'reviewed';
        }
        if ($this->auto_confirmed_at) {
            return 'auto_confirmed';
        }
        if ($this->saved_at) {
            return 'pending_review';
        }
        return 'in_progress';
    }

    /**
     * Lazy 24h check -- this app has no cron/scheduler running anywhere
     * (see docs/dashboard-logic.md #16 for the same gap on the daily
     * reset), so instead of a background sweep this mirrors
     * WeeklyReflectionController::current()'s lazy unlock check: evaluated
     * on read, by whichever request happens to touch this row next. Since
     * review is deliberately non-gating (nothing downstream waits on
     * auto_confirmed_at -- see docs/dashboard-logic.md), there's no
     * functional harm in it only becoming true whenever someone next asks.
     */
    public function maybeAutoConfirm(): void
    {
        if ($this->reviewed_at || $this->auto_confirmed_at || !$this->last_activity_at) {
            return;
        }

        if ($this->last_activity_at->lte(Carbon::now()->subHours(self::AUTO_CONFIRM_AFTER_HOURS))) {
            $this->update(['auto_confirmed_at' => Carbon::now()]);
        }
    }
}
