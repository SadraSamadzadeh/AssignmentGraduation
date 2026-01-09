<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $table = 'players';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'player_name',
        'device_id',
        'dataset_id',
        'tracking_dashboard_id',
        'player_data',
        'jersey_number',
        'position',
        'team_name',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'player_data' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get the tracking dashboard record associated with this player.
     */
    public function trackingDashboard()
    {
        return $this->belongsTo(TrackingDashboard::class, 'tracking_dashboard_id');
    }

    /**
     * Update the last seen timestamp.
     */
    public function updateLastSeen()
    {
        $this->update(['last_seen_at' => now()]);
    }

    /**
     * Find or create a player by device ID and dataset ID.
     */
    public static function findOrCreatePlayer(array $playerData, string $datasetId, ?int $trackingDashboardId = null): self
    {
        return static::updateOrCreate(
            [
                'device_id' => $playerData['device_id'],
                'dataset_id' => $datasetId,
            ],
            [
                'player_name' => $playerData['name'] ?? $playerData['player_name'] ?? 'Unknown Player',
                'tracking_dashboard_id' => $trackingDashboardId,
                'player_data' => $playerData,
                'jersey_number' => $playerData['jersey_number'] ?? $playerData['number'] ?? null,
                'position' => $playerData['position'] ?? null,
                'team_name' => $playerData['team_name'] ?? $playerData['team'] ?? null,
                'first_seen_at' => $playerData['first_seen_at'] ?? now(),
                'last_seen_at' => now(),
            ]
        );
    }
}
