<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    // 2026-08-17, redesigned same day after real usage feedback: what the
    // "threads you've named" dashboard card groups by — independent of
    // valence (which stays positive/negative/neutral, untouched, still
    // powers the weekly triggers panel unrelated to this). First version
    // included 'positive'/'negative' as two of the category values
    // themselves; user feedback ("don't label them just positive an
    // negative label them work family hobbies passion study ext ... when
    // i click i'll see the entries positive or negative") moved valence
    // back to being a purely second-level distinction — colors the
    // individual thread bubbles once you're inside a category — while
    // category itself became purely domain-based. 'passion' has no
    // reliable deterministic signal in EntryExtractionService's existing
    // taxonomy (stressors/protective_factors) and is therefore never
    // auto-suggested — it's still a valid value a category can be
    // manually set to via PATCH, just one the heuristic won't guess at
    // without real signal, same conservatism principle as 'other'.
    public const CATEGORIES = [
        // work & career
        'work', 'career', 'business',
        // relationships
        'family', 'partner', 'kids', 'parents', 'friends', 'social',
        // inner life
        'self', 'mental', 'spirituality',
        // learning
        'study',
        // body & health
        'health', 'fitness', 'sleep', 'nutrition',
        // free time
        'hobbies', 'music', 'art', 'reading', 'gaming', 'travel', 'nature', 'passion',
        // life logistics
        'finances', 'housing',
        // community
        'community', 'pets',
        // catch-all
        'other',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'trigger_status',   // unconfirmed | confirmed | dismissed
        'valence',          // positive | negative | neutral | null
        'valence_suggested', // positive | negative | null
        'category',           // one of self::CATEGORIES, or null
        'category_suggested', // one of self::CATEGORIES, or null
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'entry_entity');
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }
}
