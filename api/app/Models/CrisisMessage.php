<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per message in a crisis thread. 2026-08-17, user: "create a chat
 * between the client and therapist in term of crisis", then (same day,
 * follow-up): "we only need one conversation just when the crisis happen"
 * -- threads are now anchored on the therapist_client LINK (one persistent
 * conversation per relationship), not on an individual SafetyEvent. The
 * first design anchored each crisis flag on its own SafetyEvent-scoped
 * thread; that fragmented a client's history across N separate rooms every
 * time a new flag fired, when in practice there's one ongoing relationship
 * and one place to talk. safety_event_id is now optional context (which
 * flag, if any, a given message is a direct reply about) rather than the
 * thread's identity -- a therapist's later follow-up message, or the
 * client just wanting to check in generally, isn't tied to any one flag.
 * See docs/therapist-bridge.md for the full design, including what this
 * deliberately does NOT do yet (no push/email notification -- the
 * therapist has to open the app).
 */
class CrisisMessage extends Model
{
    protected $fillable = ['therapist_client_id', 'safety_event_id', 'sender_id', 'body'];

    protected function casts(): array
    {
        // Same treatment as Entry::$body — this is crisis-context content
        // between two real people, at least as sensitive as journal text.
        return ['body' => 'encrypted'];
    }

    public function therapistClient(): BelongsTo
    {
        return $this->belongsTo(TherapistClient::class);
    }

    public function safetyEvent(): BelongsTo
    {
        return $this->belongsTo(SafetyEvent::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
