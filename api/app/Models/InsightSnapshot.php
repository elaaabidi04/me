<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A frozen copy of one dashboard panel at the moment a client chose to
 * share it with their therapist. See the creating migration's docblock
 * and docs/therapist-bridge.md §1 for why this exists alongside the
 * already-scaffolded `Share` model rather than replacing it -- Share
 * stays the generic per-item consent grant/revoke mechanism, this is
 * the first real `shareable` target for it.
 */
class InsightSnapshot extends Model
{
    protected $fillable = [
        'user_id', 'panel_key', 'scope', 'title', 'period_label', 'caption', 'rows', 'note',
        'therapist_note', 'daily_pattern',
    ];

    protected function casts(): array
    {
        return [
            'rows'          => 'array',
            'daily_pattern' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Each share action creates exactly one snapshot + one Share row
    // together (see SharedInsightController::store()) -- never a
    // snapshot shared twice under two Share rows -- so this is a real
    // one-to-one in practice, not just in shape.
    public function share(): MorphOne
    {
        return $this->morphOne(Share::class, 'shareable');
    }
}
