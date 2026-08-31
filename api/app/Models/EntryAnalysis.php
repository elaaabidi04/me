<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryAnalysis extends Model
{
    protected $fillable = [
        'entry_id',
        'mood',
        'sentiment',
        'rumination_score',
        'self_ref_density',
        'confidence',
        'distress_flag',
        'openness',
        'notable_entity_id',
        'raw_json',
        // Extraction schema v2 — see EntryExtractionService::systemPrompt()
        // and docs/ai-behavior.md §6.
        'emotions',
        'stressors',
        'protective_factors',
        'needs',
        'behaviors',
        'thinking_patterns',
        'recovered',
        'extraction_confidence',
        'extraction_issues',
    ];

    protected function casts(): array
    {
        return [
            'distress_flag' => 'boolean',
            'raw_json' => 'array',
            'emotions' => 'array',
            'stressors' => 'array',
            'protective_factors' => 'array',
            'needs' => 'array',
            'behaviors' => 'array',
            'thinking_patterns' => 'array',
            'recovered' => 'boolean',
            'extraction_confidence' => 'float',
            'extraction_issues' => 'array',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function notableEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'notable_entity_id');
    }

    public function corrections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AnalysisCorrection::class);
    }
}
