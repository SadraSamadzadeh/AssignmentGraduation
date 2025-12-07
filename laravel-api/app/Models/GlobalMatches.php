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
        "match_score",
        "time_proximity_score",
        "duration_similarity_score",
        "temporal_overlap_score",
        "match_details",
        "created_by_user_id",
        "reviewed_by_user_id",
        "reviewed_at",
        "rejection_reason",
        "matched_at",
    ];

    protected $casts = [
        "match_details" => "array",
        "tracking_data" => "array", 
        "video_data" => "array",
        "match_score" => "decimal:2",
        "time_proximity_score" => "decimal:2",
        "duration_similarity_score" => "decimal:2",
        "temporal_overlap_score" => "decimal:2",
        "matched_at" => "datetime",
        "reviewed_at" => "datetime",
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
     * Get the user who reviewed this match.
     */
    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Get match history for this match.
     */
    public function history()
    {
        return $this->hasMany(MatchHistory::class, 'global_match_id');
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
