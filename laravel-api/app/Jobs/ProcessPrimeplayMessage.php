<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        Log::channel('stack')->info('Message received', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'received_at' => now()->toDateTimeString()
        ]);

        $eventType = $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? null;
        
        if ($eventType) {
            switch ($eventType) {
                case 'MatchImportCompleted':
                    $this->handleMatchImportCompleted();
                    break;
                    
                case 'LiveDataRecordingStopped':
                case 'live.completed':
                case 'recording.completed':
                    $this->handleLiveDataRecordingStopped();
                    break;
                    
                default:
                    $this->storeGenericMessage();
            }
        } else {
            $this->storeGenericMessage();
        }
    }

    protected function handleMatchImportCompleted(): void
    {
        // Support multiple ID formats
        $datasetId = $this->messageData['datasetId'] 
            ?? $this->messageData['match']['genius_match_id'] 
            ?? $this->messageData['match']['id'] 
            ?? null;
        
        if (!$datasetId) {
            Log::warning('Primeplay message missing datasetId or match.id', ['message' => $this->messageData]);
            return;
        }
        
        try {
            // Check if matching video data exists in cache
            $videoCacheKey = "video:match:{$datasetId}";
            $videoData = Cache::get($videoCacheKey);
            
            if ($videoData) {
                $this->createGlobalMatch($datasetId, $videoData);
                Log::info('Match found and created', ['dataset_id' => $datasetId]);
            } else {
                $this->storeTemporaryTracking($datasetId);
                Log::info('Tracking data stored temporarily', ['dataset_id' => $datasetId]);
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
     * Create a global match and cleanup temporary data (overloaded for video matching)
     */
    protected function createGlobalMatch($datasetId, $videoData, $trackingData = null): void
    {
        // If trackingData is provided, video arrived first
        if ($trackingData !== null) {
            $this->createGlobalMatchVideoFirst($datasetId, $videoData, $trackingData);
            return;
        }
        
        // Otherwise, tracking arrived first (original logic)
        DB::transaction(function () use ($datasetId, $videoData) {
            // Get video_id from video data
            $videoId = $videoData['videoId'] 
                ?? $videoData['sessionId'] 
                ?? $videoData['match']['sa_recording_id'] ?? null
                ?? "video_{$datasetId}";
            
            // Create global match
            GlobalMatches::create([
                'global_match_id' => "match_{$datasetId}_{$videoId}_" . now()->timestamp,
                'tracking_id' => $datasetId,
                'video_id' => $videoId,
                'match_score' => 100.00,
                'confidence_level' => 'high',
                'match_details' => [
                    'match_type' => 'auto_matched',
                    'matched_by' => 'system',
                    'match_criteria' => 'dataset_id',
                ],
                'tracking_data' => $this->messageData,
                'video_data' => $videoData,
                'status' => 'confirmed',
                'processed_by' => 'system',
                'matched_at' => now(),
            ]);
            
            // Remove from cache (both tracking and video)
            $trackingCacheKey = "primeplay:match:{$datasetId}";
            $videoCacheKey = "video:match:{$datasetId}";
            Cache::forget($trackingCacheKey);
            Cache::forget($videoCacheKey);
            
            // Delete any temporary records from tracking_dashboard and video_dashboard
            TrackingDashboard::where('tracking_id', $datasetId)
                ->where('source_system', 'primeplay')
                ->delete();
                
            VideoDashboard::where('video_id', $videoId)
                ->orWhere('video_id', $datasetId)
                ->delete();
            
            Log::info('Global match created and temporary data cleaned up', [
                'tracking_id' => $datasetId,
                'video_id' => $videoId,
            ]);
        });
    }
    
    /**
     * Create global match when video data arrives before tracking data
     */
    protected function createGlobalMatchVideoFirst($datasetId, $videoData, $trackingData): void
    {
        DB::transaction(function () use ($datasetId, $videoData, $trackingData) {
            $videoId = $videoData['match']['sa_recording_id'] ?? "video_{$datasetId}";
            
            // Create global match
            GlobalMatches::create([
                'global_match_id' => "match_{$datasetId}_{$videoId}_" . now()->timestamp,
                'tracking_id' => $datasetId,
                'video_id' => $videoId,
                'match_score' => 100.00,
                'confidence_level' => 'high',
                'match_details' => [
                    'match_type' => 'auto_matched',
                    'matched_by' => 'system',
                    'match_criteria' => 'dataset_id',
                ],
                'tracking_data' => $trackingData,
                'video_data' => $videoData,
                'status' => 'confirmed',
                'processed_by' => 'system',
                'matched_at' => now(),
            ]);
            
            // Remove from cache
            Cache::forget("primeplay:match:{$datasetId}");
            Cache::forget("video:match:{$datasetId}");
            
            // Delete temporary records
            TrackingDashboard::where('tracking_id', $datasetId)->delete();
            VideoDashboard::where('video_id', $videoId)->orWhere('video_id', $datasetId)->delete();
            
            Log::info('Global match created (video arrived first)', [
                'tracking_id' => $datasetId,
                'video_id' => $videoId,
            ]);
        });
    }

    /**
     * Store tracking data temporarily (waiting for video match)
     */
    protected function storeTemporaryTracking($datasetId): void
    {
        // Store in database
        TrackingDashboard::create([
            'tracking_id' => $datasetId,
            'tracking_reference' => "primeplay_{$datasetId}",
            'tracking_data' => $this->messageData,
            'source_system' => 'primeplay',
            'status' => 'unmatched',
            'received_at' => now(),
        ]);
        
        // Store in cache with 24h TTL
        $cacheKey = "primeplay:match:{$datasetId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Tracking data stored temporarily', [
            'tracking_id' => $datasetId,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
    }

    protected function handleLiveDataRecordingStopped(): void
    {
        // Support multiple ID formats for video/recording data
        $sessionId = $this->messageData['sessionId'] 
            ?? $this->messageData['match']['sa_recording_id']
            ?? $this->messageData['match']['genius_match_id']
            ?? $this->messageData['match']['id']
            ?? null;
        
        if (!$sessionId) {
            Log::warning('Video message missing sessionId or match identifiers', ['message' => $this->messageData]);
            return;
        }
        
        try {
            // Check if matching tracking data exists in cache
            $trackingCacheKey = "primeplay:match:{$sessionId}";
            $trackingData = Cache::get($trackingCacheKey);
            
            if ($trackingData) {
                $this->createGlobalMatch($sessionId, $this->messageData, $trackingData);
                Log::info('Match found and created', ['session_id' => $sessionId]);
            } else {
                $this->storeTemporaryVideo($sessionId);
                Log::info('Video data stored temporarily', ['session_id' => $sessionId]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to store live recording data', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
            throw $e;
        }
    }

    /**
     * Store video data temporarily (waiting for tracking match)
     */
    protected function storeTemporaryVideo($matchId): void
    {
        $videoId = $this->messageData['match']['sa_recording_id'] ?? "video_{$matchId}";
        
        // Store in database
        VideoDashboard::create([
            'video_id' => $videoId,
            'video_reference' => $videoId,
            'video_data' => $this->messageData,
            'source_system' => 'video_dashboard',
            'status' => 'unmatched',
            'received_at' => now(),
        ]);
        
        // Store in cache with 24h TTL
        $cacheKey = "video:match:{$matchId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Video data stored temporarily', [
            'video_id' => $videoId,
            'match_id' => $matchId,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
    }

    protected function storeGenericMessage(): void
    {
        try {
            TrackingDashboard::create([
                'tracking_id' => $this->messageData['datasetId'] ?? $this->messageData['sessionId'] ?? null,
                'tracking_data' => $this->messageData,
                'source_system' => 'primeplay',
                'received_at' => now(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store Primeplay message in database', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
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
