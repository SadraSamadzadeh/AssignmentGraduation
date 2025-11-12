<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackingMatch extends Model
{
    protected $table = 'tracking_matches';
    
    protected $fillable = [
        'tracking_id',
        'name',
        'team_name',
        'start_time',
        'end_time',
        'avg_total_time_active'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(TrackingActivity::class, 'tracking_match_id');
    }

    public function globalMatch(): HasOne
    {
        return $this->hasOne(GlobalMatch::class, 'tracking_match_id');
    }
}