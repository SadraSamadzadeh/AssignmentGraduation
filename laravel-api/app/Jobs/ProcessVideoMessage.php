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
        Log::channel('stack')->info('Video message received', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'received_at' => now()->toDateTimeString()
        ]);

        // Extract video/dataset ID from message with flexible field names
        $datasetId = $this->messageData['datasetId'] 
            ?? $this->messageData['matchId'] 
            ?? $this->messageData['match_id']
            ?? $this->messageData['match']['id'] 
            ?? $this->messageData['match']['genius_match_id']
            ?? null;
            
        $videoId = $this->messageData['videoId'] 
            ?? $this->messageData['sessionId']
            ?? $this->messageData['match']['sa_recording_id']
            ?? null;
        
        if (!$datasetId && !$videoId) {
            Log::warning('Video message missing identifiable IDs', ['message' => $this->messageData]);
            $this->storeGenericMessage();
            return;
        }
        
        // Use datasetId as primary identifier for matching
        $matchId = $datasetId ?? $videoId;
        
        try {
            // Check if matching tracking data exists in cache
            $trackingCacheKey = "primeplay:match:{$matchId}";
            $trackingData = Cache::get($trackingCacheKey);
            
            if ($trackingData) {
                $this->createGlobalMatch($matchId, $videoId, $trackingData);
                Log::info('Match found and created', ['match_id' => $matchId]);
            } else {
                $this->storeTemporaryVideo($matchId, $videoId);
                Log::info('Video data stored temporarily', ['match_id' => $matchId]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to process video message', [
                'error' => $e->getMessage(),
                'match_id' => $matchId,
                'message' => $this->messageData
            ]);
            throw $e;
        }
    }

    /**
     * Create a global match and cleanup temporary data
     */
    protected function createGlobalMatch($datasetId, $videoId, array $trackingData): void
    {
        DB::transaction(function () use ($datasetId, $videoId, $trackingData) {
            // Use video_id if provided, otherwise use dataset_id
            $finalVideoId = $videoId ?? "video_{$datasetId}";
            
            // Create global match
            GlobalMatches::create([
                'global_match_id' => "match_{$datasetId}_{$finalVideoId}_" . now()->timestamp,
                'tracking_id' => $datasetId,
                'video_id' => $finalVideoId,
                'match_score' => 100.00,
                'confidence_level' => 'high',
                'match_details' => [
                    'match_type' => 'auto_matched',
                    'matched_by' => 'system',
                    'match_criteria' => 'dataset_id',
                ],
                'tracking_data' => $trackingData,
                'video_data' => $this->messageData,
                'status' => 'confirmed',
                'processed_by' => 'system',
                'matched_at' => now(),
            ]);
            
            // Remove from cache (both video and tracking)
            $videoCacheKey = "video:match:{$datasetId}";
            $trackingCacheKey = "primeplay:match:{$datasetId}";
            Cache::forget($videoCacheKey);
            Cache::forget($trackingCacheKey);
            
            // Delete any temporary records from video_dashboard and tracking_dashboard
            VideoDashboard::where('video_id', $finalVideoId)
                ->orWhere('video_id', $datasetId)
                ->delete();
                
            TrackingDashboard::where('tracking_id', $datasetId)
                ->where('source_system', 'primeplay')
                ->delete();
            
            Log::info('Global match created and temporary data cleaned up', [
                'tracking_id' => $datasetId,
                'video_id' => $finalVideoId,
            ]);
        });
    }

    /**
     * Store video data temporarily (waiting for tracking match)
     */
    protected function storeTemporaryVideo($datasetId, $videoId): void
    {
        $finalVideoId = $videoId ?? "video_{$datasetId}";
        
        // Store in database
        VideoDashboard::create([
            'video_id' => $finalVideoId,
            'video_reference' => $finalVideoId,
            'video_data' => $this->messageData,
            'source_system' => 'video_dashboard',
            'status' => 'unmatched',
            'received_at' => now(),
        ]);
        
        // Store in cache with 24h TTL
        $cacheKey = "video:match:{$datasetId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Video data stored temporarily', [
            'video_id' => $finalVideoId,
            'dataset_id' => $datasetId,
            'cache_key' => $cacheKey,
            'expires_at' => now()->addHours(24)->toDateTimeString(),
        ]);
    }

    /**
     * Store generic video message when IDs cannot be extracted
     */
    protected function storeGenericMessage(): void
    {
        try {
            $genericId = 'unknown_' . now()->timestamp;
            
            VideoDashboard::create([
                'video_id' => $genericId,
                'video_reference' => $genericId,
                'video_data' => $this->messageData,
                'source_system' => 'video_dashboard',
                'status' => 'unmatched',
                'received_at' => now(),
            ]);
            
            Log::warning('Video message stored with generic ID', [
                'video_id' => $genericId,
                'message' => $this->messageData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store generic video message', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
        }
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
