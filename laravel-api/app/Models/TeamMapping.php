<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMapping extends Model
{
    protected $table = 'team_mappings';

    protected $fillable = [
        'video_team_id',
        'primeplay_team_id',
        'video_team_name',
        'primeplay_team_name',
        'confidence_score',
        'times_matched',
        'last_matched_at',
        'status',
        'match_details',
        'confirmed_at',
    ];

    protected $casts = [
        'match_details' => 'array',
        'confidence_score' => 'decimal:2',
        'times_matched' => 'integer',
        'last_matched_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Scope to get active mappings.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get inactive mappings.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }
}
