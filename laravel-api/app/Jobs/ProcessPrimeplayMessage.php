<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessPrimeplayMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;

    protected $messageData;
    protected $routingKey;

    public function __construct(array $messageData, string $routingKey = 'unknown')
    {
        $this->messageData = $messageData;
        $this->routingKey = $routingKey;
    }

    public function handle(): void
    {
        Log::info('Processing Primeplay tracking message', [
            'routing_key' => $this->routingKey,
            'event_type' => $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? 'unknown',
            'received_at' => now()->toDateTimeString()
        ]);

        $eventType = $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? null;
        
        // Only handle tracking-related events OR handle any message with tracking_id
        if ($eventType === 'MatchImportCompleted' || $eventType === 'match.import.completed' || isset($this->messageData['tracking_id'])) {
            $this->handleMatchImportCompleted();
        }
    }

    /**
     * Handle MatchImportCompleted event
     * Stores tracking data and attempts immediate matching via MatchCoordinator
     */
    protected function handleMatchImportCompleted(): void
    {
        // Extract tracking ID - support multiple formats
        $datasetId = $this->messageData['tracking_id']
            ?? $this->messageData['datasetId'] 
            ?? $this->messageData['matchData']['datasetId']
            ?? $this->messageData['match']['id'] 
            ?? null;
        
        if (!$datasetId) {
            Log::warning('Primeplay message missing datasetId/tracking_id', ['message' => $this->messageData]);
            return;
        }
        
        try {
            // Store tracking data first
            $trackingDashboard = $this->storeTemporaryTracking($datasetId);
            
            // Extract and store player data from the event
            $this->extractAndStorePlayers($trackingDashboard, $datasetId);
            
            // Attempt immediate matching using MatchCoordinator
            // MatchCoordinator will check team mapping first, then similarity
            $coordinator = app(\App\Services\MatchCoordinator::class);
            $match = $coordinator->matchTrackingToVideos($trackingDashboard);
            
            if ($match) {
                Log::info('Immediate match created on tracking ingestion', [
                    'tracking_id' => $datasetId,
                    'video_id' => $match->video_id,
                    'match_id' => $match->global_match_id,
                    'score' => $match->match_score
                ]);
            } else {
                Log::info('Tracking stored, no immediate match found (will retry in scheduler)', [
                    'tracking_id' => $datasetId
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to process Primeplay match import', [
                'error' => $e->getMessage(),
                'dataset_id' => $datasetId,
                'message' => $this->messageData
            ]);
            throw $e;
        }
    }

    /**
     * Store tracking data temporarily (waiting for match)
     * 
     * @return TrackingDashboard The stored tracking dashboard record
     */
    protected function storeTemporaryTracking($datasetId): TrackingDashboard
    {
        // Extract fields from message data - support flat and nested formats
        $matchData = $this->messageData['matchData'] ?? $this->messageData['tracking_data']['matchData'] ?? [];
        $startTime = $this->messageData['start_time'] ?? $matchData['startTime'] ?? $matchData['start'] ?? null;
        $endTime = $this->messageData['end_time'] ?? $matchData['endTime'] ?? $matchData['end'] ?? null;
        
        // Calculate duration in minutes
        $durationMinutes = null;
        if ($startTime && $endTime) {
            try {
                $start = new \DateTime($startTime);
                $end = new \DateTime($endTime);
                $durationMinutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
            } catch (\Exception $e) {
                Log::warning('Failed to calculate duration', ['error' => $e->getMessage()]);
            }
        }
        
        // Extract event date
        $eventDate = $this->messageData['event_date'] ?? null;
        if (!$eventDate && $startTime) {
            try {
                $eventDate = (new \DateTime($startTime))->format('Y-m-d');
            } catch (\Exception $e) {
                $eventDate = now()->format('Y-m-d');
            }
        }
        if (!$eventDate) {
            $eventDate = now()->format('Y-m-d');
        }
        
        // Store in database (prevent duplicates with updateOrCreate)
        $trackingDashboard = TrackingDashboard::updateOrCreate(
            ['tracking_id' => $datasetId],
            [
                'tracking_data' => $this->messageData,
                'source_system' => 'primeplay',
                'event_date' => $eventDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'dataset_name' => $matchData['name'] ?? null,
                'team_name' => $this->messageData['team_name'] ?? $matchData['teamName'] ?? null,
                'status' => 'unmatched',
                'received_at' => now(),
                'expires_at' => now()->addDays(7),
            ]
        );
        
        // Store in cache with 24h TTL
        $cacheKey = "primeplay:match:{$datasetId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Tracking data stored', [
            'tracking_id' => $datasetId,
            'event_date' => $eventDate,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
        
        return $trackingDashboard;
    }

    /**
     * Extract and store player data from Primeplay event
     * 
     * @param TrackingDashboard $trackingDashboard
     * @param string $datasetId
     * @return void
     */
    protected function extractAndStorePlayers(TrackingDashboard $trackingDashboard, string $datasetId): void
    {
        try {
            // Extract players from message data
            $players = $this->messageData['matchData']['players'] 
                ?? $this->messageData['players'] 
                ?? $this->messageData['matchData']['roster']
                ?? [];
            
            if (empty($players)) {
                Log::debug('No player data found in Primeplay event', [
                    'dataset_id' => $datasetId
                ]);
                return;
            }

            $playersCreated = 0;
            $playersUpdated = 0;
            
            foreach ($players as $playerData) {
                // Ensure player has required fields
                $deviceId = $playerData['device_id'] 
                    ?? $playerData['deviceId'] 
                    ?? $playerData['sensor_id']
                    ?? null;
                
                if (!$deviceId) {
                    Log::warning('Player missing device_id, skipping', [
                        'player_data' => $playerData,
                        'dataset_id' => $datasetId
                    ]);
                    continue;
                }

                // Prepare player data
                $normalizedPlayerData = [
                    'device_id' => $deviceId,
                    'name' => $playerData['name'] 
                        ?? $playerData['player_name'] 
                        ?? $playerData['fullName']
                        ?? 'Unknown Player',
                    'jersey_number' => $playerData['jersey_number'] 
                        ?? $playerData['number'] 
                        ?? $playerData['jerseyNumber']
                        ?? null,
                    'position' => $playerData['position'] ?? null,
                    'team_name' => $playerData['team_name'] 
                        ?? $playerData['team'] 
                        ?? $this->messageData['matchData']['teamName']
                        ?? null,
                ];

                // Create or update player
                $player = Player::findOrCreatePlayer(
                    $normalizedPlayerData,
                    $datasetId,
                    $trackingDashboard->id
                );

                if ($player->wasRecentlyCreated) {
                    $playersCreated++;
                } else {
                    $playersUpdated++;
                }
            }

            Log::info('Player data extracted and stored', [
                'dataset_id' => $datasetId,
                'tracking_dashboard_id' => $trackingDashboard->id,
                'players_created' => $playersCreated,
                'players_updated' => $playersUpdated,
                'total_players' => count($players)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to extract player data from Primeplay event', [
                'dataset_id' => $datasetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw - player extraction failure shouldn't stop tracking processing
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to process Primeplay message', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
