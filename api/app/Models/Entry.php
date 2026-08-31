<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Entry extends Model
{
    protected $fillable = [
        'user_id',
        'daily_intent_id',
        'body',
        'in_vault',
    ];

    protected function casts(): array
    {
        return [
            // Journal text is sensitive — encrypted at rest via Laravel's cast.
            'body' => 'encrypted',
            'in_vault' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyIntent(): BelongsTo
    {
        return $this->belongsTo(DailyIntent::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(EntryAnalysis::class);
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entry_entity');
    }

    public function coachMessage(): HasOne
    {
        return $this->hasOne(CoachMessage::class);
    }
}
