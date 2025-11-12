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
        "match_score",
        "confidence_level",
        "match_details",
        "tracking_data",
        "video_data",
        "processed_by",
        "matched_at",
    ];

    protected $casts = [
        "match_details" => "array",
        "tracking_data" => "array", 
        "video_data" => "array",
        "matched_at" => "datetime",
        "match_score" => "decimal:2",
    ];

    public function scopeSuccessful($query)
    {
        return $query->where("match_score", ">=", 60);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where("matched_at", ">=", now()->subDays($days));
    }

    public function isSuccessful()
    {
        return $this->match_score >= 60;
    }
}
