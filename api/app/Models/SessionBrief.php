<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * 2026-08-19: Pre-session brief — a client-authored note written before a
 * scheduled session ("here's what I'd want my therapist to know before we
 * talk"). Shared via the same polymorphic Share gate InsightSnapshot uses
 * (see docs/therapist-bridge.md §8): creating a brief and sharing it are
 * one atomic operation in SessionBriefController::store(). A brief can be
 * revoked (Share::revoked_at set) without deleting the row, matching the
 * same revoke-not-delete discipline as shared insights.
 *
 * therapy_session_id is optional — a brief written without a specific
 * session in mind is still valid. When set, it's a hint to the therapist
 * about which session this was intended for; nullOnDelete in the migration
 * means the FK clears if the session is cancelled without losing the content.
 */
class SessionBrief extends Model
{
    protected $fillable = [
        'client_id', 'therapist_id', 'therapy_session_id', 'content',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'therapist_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    // In practice a brief is only ever shared once (same MorphOne reasoning
    // as InsightSnapshot::share()) -- re-sharing creates a new brief+share.
    public function share(): MorphOne
    {
        return $this->morphOne(Share::class, 'shareable');
    }
}
