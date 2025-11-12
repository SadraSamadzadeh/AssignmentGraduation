<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalMatch extends Model
{
    protected $table = 'global_matches';
    
    protected $fillable = [
        'global_id',
        'tracking_match_id',
        'video_match_id',
        'match_score',
        'confidence_level',
        'reasons',
        'created_at'
    ];

    protected $casts = [
        'reasons' => 'array',
        'match_score' => 'float'
    ];

    public function trackingMatch(): BelongsTo
    {
        return $this->belongsTo(TrackingMatch::class, 'tracking_match_id');
    }

    public function videoMatch(): BelongsTo
    {
        return $this->belongsTo(VideoMatch::class, 'video_match_id');
    }

    public function getConfidenceLevelAttribute(): string
    {
        if ($this->match_score >= 80) return 'confident';
        if ($this->match_score >= 60) return 'likely';
        if ($this->match_score >= 40) return 'possible';
        return 'unlikely';
    }
}