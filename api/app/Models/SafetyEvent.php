<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafetyEvent extends Model
{
    protected $fillable = ['user_id', 'entry_id', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    // 2026-08-17: every SafetyEvent is now also a crisis thread's anchor --
    // see CrisisMessage's docblock and docs/therapist-bridge.md. This was a
    // write-only audit trail before (reviewed_at is still never set by
    // anything; that gap is unchanged), so this is purely additive.
    public function messages(): HasMany
    {
        return $this->hasMany(CrisisMessage::class)->orderBy('created_at');
    }
}
