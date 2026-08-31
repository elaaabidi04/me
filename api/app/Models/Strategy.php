<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Strategy extends Model
{
    protected $fillable = [
        'user_id',
        'entity_id',
        'description',
        'source_entry_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'source_entry_id');
    }
}
