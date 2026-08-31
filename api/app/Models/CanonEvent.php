<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable, user-confirmed thing the coach may reference across days --
 * the one piece of cross-day memory this app has (see docs/ai-behavior.md
 * §4's "same-day memory" note and §11 "Canon-event memory" / the
 * 2026-08-14 two-stage memory pipeline update). Despite the class name
 * (kept for continuity -- see the 2026-08-14 migration's docblock for why
 * it wasn't renamed), a row is no longer only a "notable event": category
 * spans fact/preference/goal/pattern/event, see
 * ExtractionTaxonomy::MEMORY_CATEGORIES.
 *
 * Candidates come from a two-stage pipeline: Stage 1
 * (EntryExtractionService's memory_candidates field) proposes shape-valid
 * candidates; Stage 2 (MemoryEvaluatorService) scores each one on
 * importance/stability/confidence and can veto it outright. Only
 * candidates that survive both stages become a row here, and even then
 * with status='suggested' -- nothing is usable by CoachService until a
 * human confirms it AND an embedding is populated (computed at confirm
 * time, see CanonEventController::update()), the same extraction-
 * suggests/user-confirms shape already used for Strategy and entity
 * valence, applied to a higher-stakes kind of memory that persists
 * indefinitely and gets surfaced back verbatim.
 */
class CanonEvent extends Model
{
    protected $fillable = [
        'user_id',
        'entity_id',
        'source_entry_id',
        'description',
        'quote',
        'category',
        'status',
        'importance',
        'stability',
        'confidence',
        'reason',
        'embedding',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding'    => 'array',
            'confirmed_at' => 'datetime',
            'importance'   => 'float',
            'stability'    => 'float',
            'confidence'   => 'float',
        ];
    }

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
