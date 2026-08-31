<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyIntent extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'pick', // engage | witness (light/rest retired 2026-08-12 — see CoachService::PICK_TO_MODE)
        'analysis_requests',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
