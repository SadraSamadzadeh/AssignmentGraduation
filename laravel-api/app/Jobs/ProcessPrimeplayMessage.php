<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
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
        Log::channel('stack')->info('Processing Primeplay tracking message', [
            'routing_key' => $this->routingKey,
            'event_type' => $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? 'unknown',
            'received_at' => now()->toDateTimeString()
        ]);

        $eventType = $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? null;
        
        // Only handle tracking-related events
        if ($eventType === 'MatchImportCompleted' || $eventType === 'match.import.completed') {
            $this->handleMatchImportCompleted();
        }
    }

    /**
     * Handle MatchImportCompleted event
     * Stores tracking data and attempts immediate matching via MatchCoordinator
     */
    protected function handleMatchImportCompleted(): void
    {
        // Extract tracking ID (use datasetId from matchData)
        $datasetId = $this->messageData['datasetId'] 
            ?? $this->messageData['matchData']['datasetId']
            ?? $this->messageData['match']['id'] 
            ?? null;
        
        if (!$datasetId) {
            Log::warning('Primeplay message missing datasetId', ['message' => $this->messageData]);
            return;
        }
        
        try {
            // Store tracking data first
            $trackingDashboard = $this->storeTemporaryTracking($datasetId);
            
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
        // Extract fields from message data
        $matchData = $this->messageData['matchData'] ?? [];
        $startTime = $matchData['startTime'] ?? $matchData['start'] ?? null;
        $endTime = $matchData['endTime'] ?? $matchData['end'] ?? null;
        
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
        $eventDate = null;
        if ($startTime) {
            try {
                $eventDate = (new \DateTime($startTime))->format('Y-m-d');
            } catch (\Exception $e) {
                $eventDate = now()->format('Y-m-d');
            }
        }
        
        // Store in database (prevent duplicates with updateOrCreate)
        $trackingDashboard = TrackingDashboard::updateOrCreate(
            ['tracking_id' => $datasetId],
            [
                'tracking_data' => $this->messageData,
                'source_system' => 'primeplay',
                'event_date' => $eventDate ?? now()->format('Y-m-d'),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'dataset_name' => $matchData['name'] ?? null,
                'team_name' => $matchData['teamName'] ?? null,
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
