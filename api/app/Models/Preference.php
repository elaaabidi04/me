<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preference extends Model
{
    protected $fillable = [
        'user_id',
        'tone',
        'nudge_level',
        'language',
        'product_mode',
        'theme',
        'top_values',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'top_values'            => 'array',
            'onboarding_completed'  => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
