<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VideoMatch extends Model
{
    protected $table = 'video_matches';
    
    protected $fillable = [
        'video_id',
        'club_id',
        'club_name',
        'home_team',
        'away_team',
        'start_time',
        'end_time',
        'timezone'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function globalMatch(): HasOne
    {
        return $this->hasOne(GlobalMatch::class, 'video_match_id');
    }
}