<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisCorrection extends Model
{
    protected $fillable = [
        'entry_analysis_id',
        'field',
        'original_value',
        'action',
        'corrected_value',
    ];

    public function entryAnalysis(): BelongsTo
    {
        return $this->belongsTo(EntryAnalysis::class);
    }
}
