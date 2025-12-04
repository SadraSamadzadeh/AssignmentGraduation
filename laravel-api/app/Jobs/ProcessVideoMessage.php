<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
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
        Log::channel('stack')->info('Video message received', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'received_at' => now()->toDateTimeString()
        ]);

        // Extract video ID from match_data.id (primary identifier)
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
        
        // Use videoId for both matching and storage
        $datasetId = $videoId;
        $matchId = $videoId;
        
        try {
            // Check if matching tracking data exists in cache
            $trackingCacheKey = "primeplay:match:{$matchId}";
            $trackingData = Cache::get($trackingCacheKey);
            
            if ($trackingData) {
                $this->createGlobalMatch($matchId, $videoId, $trackingData);
                Log::info('Match found and created', ['match_id' => $matchId]);
                return;
            }
            
            // If no exact match, try similarity matching with all unmatched tracking
            $matchFound = $this->findSimilarTrackingMatch($matchId, $videoId);
            
            if (!$matchFound) {
                $this->storeTemporaryVideo($matchId, $videoId);
                Log::info('Video data stored temporarily (no match found)', ['match_id' => $matchId]);
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
     * Try to find a similar tracking match using similarity scoring
     */
    protected function findSimilarTrackingMatch($matchId, $videoId): bool
    {
        $unmatchedTracking = TrackingDashboard::where('status', 'unmatched')
            ->orWhereNull('status')
            ->get();
            
        if ($unmatchedTracking->isEmpty()) {
            return false;
        }
        
        $matchingService = new MatchingService();
        $bestMatch = null;
        $bestScore = 0;
        
        // Prepare video data in format expected by MatchingService
        $videoFormatted = $this->formatVideoDataForMatching($this->messageData, $videoId ?? $matchId);
        
        foreach ($unmatchedTracking as $tracking) {
            $trackingData = is_string($tracking->message_content) 
                ? json_decode($tracking->message_content, true) 
                : $tracking->message_content;
            
            // Format tracking data for matching service
            $trackingFormatted = $this->formatTrackingDataForMatching($trackingData, $tracking->tracking_id);
            
            $result = $matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
            
            // Skip early exits and require minimum 65 score
            if (isset($result['early_exit']) && $result['early_exit']) {
                continue;
            }
            
            if ($result['score'] >= 65 && $result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestMatch = [
                    'tracking' => $tracking,
                    'tracking_data' => $trackingData,
                    'match_result' => $result
                ];
            }
        }
        
        if ($bestMatch) {
            $this->createGlobalMatchWithScore(
                $bestMatch['tracking']->tracking_id,
                $videoId ?? $matchId,
                $bestMatch['tracking_data'],
                $bestMatch['match_result']
            );
            
            // Delete the matched tracking record
            $bestMatch['tracking']->delete();
            
            Log::info('Similarity match found and created (video first)', [
                'tracking_id' => $bestMatch['tracking']->tracking_id,
                'video_id' => $videoId ?? $matchId,
                'score' => $bestMatch['match_result']['score'],
                'confidence' => $bestMatch['match_result']['confidence']
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Format tracking data for MatchingService
     */
    protected function formatTrackingDataForMatching(array $trackingData, $datasetId): array
    {
        $matchData = $trackingData['matchData'] ?? $trackingData;
        
        return [
            'id' => $datasetId,
            'startTime' => $matchData['start'] ?? $matchData['startTime'] ?? now()->toIso8601String(),
            'endTime' => $matchData['end'] ?? $matchData['endTime'] ?? now()->toIso8601String(),
            'teamName' => $matchData['teamName'] ?? $matchData['name'] ?? '',
        ];
    }
    
    /**
     * Format video data for MatchingService
     */
    protected function formatVideoDataForMatching(array $videoData, $videoId): array
    {
        $match = $videoData['match_data']['match'] ?? $videoData['match'] ?? $videoData;
        
        return [
            'id' => $videoId,
            'starting_at' => [
                'date' => $match['starting_at'] ?? $match['atom_starting_at'] ?? now()->toIso8601String(),
            ],
            'stopping_at' => [
                'date' => $match['stopping_at'] ?? $match['atom_stopping_at'] ?? now()->toIso8601String(),
            ],
            'timezone' => $match['timezone'] ?? 'UTC',
            'home' => [
                'name' => $match['home_team']['name'] ?? $match['home']['name'] ?? '',
            ],
            'away' => [
                'name' => $match['away_team']['name'] ?? $match['away']['name'] ?? '',
            ],
            'club' => [
                'name' => $match['club']['name'] ?? $match['home_club']['name'] ?? '',
            ],
        ];
    }
    
    /**
     * Create global match with similarity score
     */
    protected function createGlobalMatchWithScore($trackingId, $videoId, array $trackingData, array $matchResult): void
    {
        DB::transaction(function () use ($trackingId, $videoId, $trackingData, $matchResult) {
            GlobalMatches::create([
                'global_match_id' => "match_{$trackingId}_{$videoId}_" . now()->timestamp,
                'tracking_id' => $trackingId,
                'video_id' => $videoId,
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'match_details' => [
                    'match_type' => 'similarity_matched',
                    'matched_by' => 'system',
                    'match_criteria' => 'similarity_algorithm',
                    'reasons' => $matchResult['reasons'] ?? [],
                ],
                'tracking_data' => $trackingData,
                'video_data' => $this->messageData,
                'status' => $matchResult['score'] >= 85 ? 'confirmed' : 'pending_review',
                'processed_by' => 'system',
                'matched_at' => now(),
            ]);
            
            // Remove from cache
            Cache::forget("primeplay:match:{$trackingId}");
            Cache::forget("video:match:{$trackingId}");
            
            // Delete temporary records
            TrackingDashboard::where('tracking_id', $trackingId)->delete();
            VideoDashboard::where('video_id', $videoId)->delete();
        });
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
        VideoDashboard::updateOrCreate(
            ['video_id' => $finalVideoId],
            [
                'event_date' => $eventDate ?? now()->format('Y-m-d'),
                'status' => 'unmatched',
                'message_content' => $this->messageData,
                'source_system' => 'video',
                'home_club_name' => $homeClub,
                'away_club_name' => $awayClub,
                'field_name' => $this->messageData['field']['name'] ?? null,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'is_training' => $this->messageData['is_training'] ?? false,
                'received_at' => now(),
                'expires_at' => now()->addDays(7),
            ]
        );
        
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
            // Try to extract ID from match_data.id first
            $genericId = $this->messageData['match_data']['id'] 
                ?? $this->messageData['match']['id']
                ?? ('unknown_' . now()->timestamp);
            
            // Extract fields from message data (check both direct and nested under match_data)
            $matchData = $this->messageData['match_data'] ?? $this->messageData;
            $homeClub = $matchData['home']['name'] ?? null;
            $awayClub = $matchData['away']['name'] ?? null;
            $startTime = $matchData['starting_at']['date'] ?? $matchData['starting_at'] ?? null;
            $endTime = $matchData['stopping_at']['date'] ?? $matchData['stopping_at'] ?? null;
            
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
            
            VideoDashboard::updateOrCreate(
                ['video_id' => $genericId],
                [
                    'event_date' => $eventDate ?? now()->format('Y-m-d'),
                    'status' => 'unmatched',
                    'message_content' => $this->messageData,
                    'source_system' => 'video',
                    'home_club_name' => $homeClub,
                    'away_club_name' => $awayClub,
                    'field_name' => $matchData['field']['name'] ?? null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_minutes' => $durationMinutes,
                    'is_training' => $matchData['is_training'] ?? false,
                    'received_at' => now(),
                    'expires_at' => now()->addDays(7),
                ]
            );
            
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
