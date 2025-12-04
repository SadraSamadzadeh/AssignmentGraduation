<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfirmedMatch extends Model
{
    protected $fillable = [
        'video_team_id',
        'primeplay_team_id',
        'match_score',
        'match_details',
        'matched_at',
    ];

    protected $casts = [
        'match_details' => 'array',
        'matched_at' => 'datetime',
        'match_score' => 'decimal:2',
    ];
}
