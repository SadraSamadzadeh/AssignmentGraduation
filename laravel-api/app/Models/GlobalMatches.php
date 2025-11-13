<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalMatches extends Model
{
    use HasFactory;

    protected $table = "global_matches";

    protected $fillable = [
        "global_match_id",
        "tracking_id", 
        "video_id",
        "confidence_level",
        "tracking_data",
        "video_data",
        "status",
        "created_by_user_id",
        "matched_at",
    ];

    protected $casts = [
        "tracking_data" => "array", 
        "video_data" => "array",
        "matched_at" => "datetime",
    ];

    public function scopeRecent($query, $days = 7)
    {
        return $query->where("matched_at", ">=", now()->subDays($days));
    }

    /**
     * Get the user who created this match.
     */
    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope to get confirmed matches.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope to get pending matches.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get rejected matches.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
