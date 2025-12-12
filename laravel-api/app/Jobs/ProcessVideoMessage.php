<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use App\Models\TeamMapping;
use App\Services\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessVideoMessage implements ShouldQueue
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
        Log::channel('stack')->info('Processing video message', [
            'routing_key' => $this->routingKey,
            'event_type' => $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? 'unknown',
            'received_at' => now()->toDateTimeString()
        ]);

        $eventType = $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? null;
        
        // Handle video-specific events
        if (in_array($eventType, ['LiveDataRecordingStopped', 'live.completed', 'recording.completed'])) {
            $this->handleLiveDataRecordingStopped();
        } else {
            $this->handleLiveDataRecordingStopped();
        }
    }

    protected function handleLiveDataRecordingStopped(): void
    {
        $videoId = $this->messageData['match_data']['id'] 
            ?? $this->messageData['match']['id'] 
            ?? $this->messageData['videoId']
            ?? $this->messageData['id']
            ?? null;
        
        if (!$videoId) {
            Log::warning('Video message missing identifiable IDs', ['message' => $this->messageData]);
            $this->storeGenericMessage();
            return;
        }
        
        try {
            // Store video data first
            $videoDashboard = $this->storeTemporaryVideo($videoId, $videoId);
            
            $coordinator = app(\App\Services\MatchCoordinator::class);
            $match = $coordinator->matchVideoToTracking($videoDashboard);
            
            if ($match) {
                Log::info('Immediate match created on video ingestion', [
                    'video_id' => $videoId,
                    'tracking_id' => $match->tracking_id,
                    'match_id' => $match->global_match_id,
                    'score' => $match->match_score
                ]);
            } else {
                Log::info('Video stored, no immediate match found (will retry in scheduler)', [
                    'video_id' => $videoId
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to process video message', [
                'error' => $e->getMessage(),
                'video_id' => $videoId,
                'message' => $this->messageData
            ]);
            throw $e;
        }
    }


    /**
     * Store video data temporarily (waiting for match)
     * 
     * @return VideoDashboard The stored video dashboard record
     */
    protected function storeTemporaryVideo($datasetId, $videoId): VideoDashboard
    {
        $finalVideoId = $videoId ?? "video_{$datasetId}";
        
        // Extract fields from message data
        $homeClub = $this->messageData['home']['name'] ?? null;
        $awayClub = $this->messageData['away']['name'] ?? null;
        $startTime = $this->messageData['starting_at'] ?? null;
        $endTime = $this->messageData['stopping_at'] ?? null;
        
        // Calculate duration
        $durationMinutes = null;
        if ($startTime && $endTime) {
            try {
                $start = new \DateTime($startTime);
                $end = new \DateTime($endTime);
                $durationMinutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
            } catch (\Exception $e) {
                // Ignore
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
        $videoDashboard = VideoDashboard::updateOrCreate(
            ['video_id' => $finalVideoId],
            [
                'video_data' => $this->messageData,
                'source_system' => 'video',
                'event_date' => $eventDate ?? now()->format('Y-m-d'),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'home_club_name' => $homeClub,
                'away_club_name' => $awayClub,
                'field_name' => $this->messageData['field']['name'] ?? null,
                'is_training' => $this->messageData['is_training'] ?? false,
                'status' => 'unmatched',
                'received_at' => now(),
                'expires_at' => now()->addDays(7),
            ]
        );
        
        // Store in cache with 24h TTL
        $cacheKey = "video:match:{$datasetId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Video data stored', [
            'video_id' => $finalVideoId,
            'dataset_id' => $datasetId,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
        
        return $videoDashboard;
    }

   
    protected function extractVideoTeamId(array $videoData): ?string
    {
        $match = $videoData['match_data']['match'] ?? $videoData['match'] ?? $videoData;
        return $match['home_team']['id'] ?? $match['home']['id'] ?? null;
    }

    protected function extractPrimeplayTeamId(array $trackingData): ?string
    {
        $matchData = $trackingData['matchData'] ?? $trackingData;
        return $matchData['teamId'] ?? $matchData['team_id'] ?? null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to process video message', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
