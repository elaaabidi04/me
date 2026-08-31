<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachMessage extends Model
{
    protected $fillable = [
        'entry_id',
        // engage | witness | crisis are the only modes any code path can
        // produce today. hold_back is kept wired in CoachService as a
        // dormant safety backstop (see PICK_TO_MODE's docblock) but nothing
        // currently returns it. offer_door is fully retired (2026-08-12).
        'mode',
        'body',
        // Tied to offer_door's "sit with it / leave it" choice, which was
        // never wired up on the frontend even before offer_door retired —
        // was already dead, stays dead. Column left as-is (no migration).
        'door_choice', // sit | leave
        'reason',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
