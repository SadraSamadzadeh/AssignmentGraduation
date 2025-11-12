<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingActivity extends Model
{
    protected $table = 'tracking_activities';
    
    protected $fillable = [
        'tracking_match_id',
        'activity_type',
        'duration',
        'intensity',
        'details'
    ];

    protected $casts = [
        'details' => 'array'
    ];

    public function trackingMatch(): BelongsTo
    {
        return $this->belongsTo(TrackingMatch::class, 'tracking_match_id');
    }
}