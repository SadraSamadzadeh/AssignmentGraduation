<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'event_date',
        'status',
        'message_content',
        'source_system',
        'home_club_name',
        'away_club_name',
        'field_name',
        'start_time',
        'end_time',
        'duration_minutes',
        'is_training',
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
        'message_content' => 'array',
        'event_date' => 'date',
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
}
