<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class VideoDashboard extends Model
{
    use HasFactory;

    protected $table = 'video_dashboard';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'video_id',
        'video_data',
        'source_system',
        'home_club_name',
        'away_club_name',
        'field_name',
        'event_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'is_training',
        'status',
        'match_attempts',
        'last_match_attempt_at',
        'received_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'video_data' => 'array',
        'event_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_training' => 'boolean',
        'match_attempts' => 'integer',
        'duration_minutes' => 'integer',
        'last_match_attempt_at' => 'datetime',
        'received_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Increment match attempts.
     */
    public function incrementMatchAttempts()
    {
        $this->increment('match_attempts');
        $this->update(['last_match_attempt_at' => now()]);
    }

    /**
     * Scope a query to only include unmatched records.
     */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('status', 'unmatched');
    }

    /**
     * Scope a query to only include matched records.
     */
    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('status', 'matched');
    }

    /**
     * Scope a query to filter by event date.
     */
    public function scopeByEventDate(Builder $query, $date): Builder
    {
        return $query->whereDate('event_date', $date);
    }
}
