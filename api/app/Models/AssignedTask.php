<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A small task a therapist assigns to a currently-linked client -- title,
 * optional instructions, optional due date. The client checks it off
 * (TaskController::toggleComplete()); the therapist can delete one they
 * created if they change their mind. See docs/therapist-bridge.md.
 */
class AssignedTask extends Model
{
    protected $fillable = [
        'therapist_id', 'client_id', 'title', 'instructions', 'due_date', 'completed_at',
    ];

    // due_date deliberately uncast -- see the creating migration's
    // docblock. completed_at is safe to cast: it's never the search key
    // of an updateOrCreate() call anywhere this model is touched, unlike
    // the column that caused the real DailyReview bug earlier this
    // session.
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
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

    public function getIsCompleteAttribute(): bool
    {
        return $this->completed_at !== null;
    }
}
