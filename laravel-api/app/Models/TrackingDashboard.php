<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackingDashboard extends Model
{
    use HasFactory;

    protected $table = 'tracking_dashboard';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tracking_id',
        'tracking_data',
        'source_system',
        'dataset_name',
        'team_name',
        'event_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'status',
        'match_attempts',
        'last_match_attempt_at',
        'assigned_to_user_id',
        'received_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tracking_data' => 'array',
        'event_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'match_attempts' => 'integer',
        'duration_minutes' => 'integer',
        'last_match_attempt_at' => 'datetime',
        'received_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user assigned to this tracking record.
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Increment match attempts.
     */
    public function incrementMatchAttempts()
    {
        $this->increment('match_attempts');
        $this->update(['last_match_attempt_at' => now()]);
    }
}
