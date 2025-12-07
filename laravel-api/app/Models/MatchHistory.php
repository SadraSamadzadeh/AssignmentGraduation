<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchHistory extends Model
{
    use HasFactory;

    protected $table = 'match_history';

    const UPDATED_AT = null; // Only has created_at

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'global_match_id',
        'action',
        'previous_status',
        'new_status',
        'previous_score',
        'new_score',
        'changes',
        'reason',
        'performed_by_user_id',
        'performed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'changes' => 'array',
        'previous_score' => 'decimal:2',
        'new_score' => 'decimal:2',
        'performed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the match this history belongs to.
     */
    public function globalMatch()
    {
        return $this->belongsTo(GlobalMatches::class, 'global_match_id');
    }

    /**
     * Get the user who performed this action.
     */
    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * Scope to get history for a specific action.
     */
    public function scopeAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to get recent history.
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('performed_at', '>=', now()->subDays($days));
    }
}
