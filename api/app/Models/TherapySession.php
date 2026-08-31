<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A scheduled session between a therapist and a currently-linked client
 * -- see the creating migration's docblock for why this is
 * therapy_sessions, not sessions. is_soon/is_past are computed, not
 * stored -- "soon" always means "as of right now," same as every other
 * live-computed flag in this app (DailyReview::status, etc.), never a
 * snapshot that could go stale.
 */
class TherapySession extends Model
{
    private const SOON_WITHIN_HOURS = 24;

    protected $fillable = [
        'therapist_id', 'client_id', 'scheduled_at', 'notes',
        'attendance', 'outcome_note',
    ];

    // Appended so is_soon/is_past ride along in every JSON response
    // without the frontend having to recompute "is this within 24h" its
    // own way in a second place.
    protected $appends = ['is_soon', 'is_past'];

    // scheduled_at is safe to cast 'datetime' -- unlike DailyReview's
    // 'date' column (see its docblock for the real bug that taught this
    // lesson), nothing here ever does a raw-array where() search keyed
    // on scheduled_at; every lookup goes through the primary key or
    // therapist_id/client_id.
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'therapist_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    // Drives the in-app "session tomorrow" highlight this app uses
    // instead of a real push/email notification -- there is no
    // notification delivery system anywhere in this codebase (see
    // docs/therapist-bridge.md §4's list), so this is the honest
    // version of "remind me a day before": visible the moment either
    // side opens the app, not before.
    public function getIsSoonAttribute(): bool
    {
        $now = Carbon::now();
        return $this->scheduled_at->isFuture()
            && $this->scheduled_at->lte($now->copy()->addHours(self::SOON_WITHIN_HOURS));
    }

    public function getIsPastAttribute(): bool
    {
        return $this->scheduled_at->isPast();
    }
}
