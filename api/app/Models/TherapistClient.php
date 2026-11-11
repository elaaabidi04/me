<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * First model for the `therapist_client` table (2026-08-17) -- the table
 * itself was scaffolded on day one (2026-08-04, see docs/dev-log.md) as
 * part of a broader "therapist bridge" plan that was deprioritized to an
 * optional Week 4 sprint and never reached (see docs/build-roadmap.md,
 * docs/sujet.md §6/§10/§13). This model exists now because
 * the user asked for a concrete, narrower slice of that plan: code-invite
 * linking plus a crisis chat. See docs/therapist-bridge.md.
 *
 * A client is only ever linked to ONE therapist at a time -- the DB's
 * unique(therapist_id, client_id) constraint would technically allow a
 * client to link to several different therapists, but
 * TherapistLinkController::link() enforces one-at-a-time at the
 * application level (replacing any prior link) to keep "who gets the
 * automatic crisis alert" unambiguous.
 */
class TherapistClient extends Model
{
    protected $table = 'therapist_client';

    protected $fillable = ['therapist_id', 'client_id', 'focus_text', 'live_trends_enabled'];

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'therapist_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    // 2026-08-17: the single persistent crisis conversation for this
    // relationship -- see CrisisMessage's docblock.
    public function crisisMessages(): HasMany
    {
        return $this->hasMany(CrisisMessage::class)->orderBy('created_at');
    }

    // 2026-08-17: lets a therapist's client list show an unreviewed-flag
    // count per client (task: "flag counts on therapist client list")
    // without a separate query per row -- TherapistLinkController::show()
    // uses withCount('unreviewedFlags'). Joins through client_id since
    // SafetyEvent belongs to the client (user_id), not to this link row.
    public function unreviewedFlags(): HasMany
    {
        return $this->hasMany(SafetyEvent::class, 'user_id', 'client_id')->whereNull('reviewed_at');
    }
}
