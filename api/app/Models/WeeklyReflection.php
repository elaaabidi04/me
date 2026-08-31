<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyReflection extends Model
{
    protected $fillable = ['user_id', 'week_start', 'status', 'unlock_at', 'content'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'unlock_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // The server-side enforcement of "sealed until Sunday" — the client can't
    // fetch this early no matter what the UI shows.
    public function isLocked(): bool
    {
        return !in_array($this->status, ['ready', 'opened']) && now()->lt($this->unlock_at);
    }
}
