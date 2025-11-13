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
        'video_reference',
        'video_data',
        'source_system',
        'match_attempts',
        'last_match_attempt_at',
        'received_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'video_data' => 'array',
        'match_attempts' => 'integer',
        'last_match_attempt_at' => 'datetime',
        'received_at' => 'datetime',
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
