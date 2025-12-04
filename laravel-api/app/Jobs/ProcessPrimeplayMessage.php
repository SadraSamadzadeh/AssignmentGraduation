<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use App\Models\ConfirmedMatch;
use App\Services\MatchingService;
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
            $primeplayTeamId = $this->extractPrimeplayTeamId($this->messageData);
            
            if ($primeplayTeamId) {
                $confirmedMatch = ConfirmedMatch::where('primeplay_team_id', $primeplayTeamId)->first();
                if ($confirmedMatch) {
                    $video = VideoDashboard::get()
                        ->first(function($v) use ($confirmedMatch) {
                            $videoData = is_string($v->message_content) ? json_decode($v->message_content, true) : $v->message_content;
                            $videoTeamId = $this->extractVideoTeamId($videoData);
                            return $videoTeamId === $confirmedMatch->video_team_id;
                        });
                        
                    if ($video) {
                        $this->createGlobalMatch($datasetId, $video->message_content);
                        Log::info('Match created from confirmed team match', [
                            'primeplay_team_id' => $primeplayTeamId,
                            'video_team_id' => $confirmedMatch->video_team_id,
                            'original_score' => $confirmedMatch->match_score
                        ]);
                        return;
                    }
                }
            }
            
            $videoCacheKey = "video:match:{$datasetId}";
            $videoData = Cache::get($videoCacheKey);
            
            if ($videoData) {
                $this->createGlobalMatch($datasetId, $videoData);
                Log::info('Match found and created (exact ID)', ['dataset_id' => $datasetId]);
                return;
            }
            
            // If no exact match, try similarity matching with all unmatched videos
            $matchFound = $this->findSimilarVideoMatch($datasetId);
            
            if (!$matchFound) {
                $this->storeTemporaryTracking($datasetId);
                Log::info('Tracking data stored temporarily (no match found)', ['dataset_id' => $datasetId]);
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
     * Try to find a similar video match using similarity scoring
     */
    protected function findSimilarVideoMatch($datasetId): bool
    {
        $unmatchedVideos = VideoDashboard::where('status', 'unmatched')
            ->orWhereNull('status')
            ->get();
            
        if ($unmatchedVideos->isEmpty()) {
            return false;
        }
        
        $matchingService = new MatchingService();
        $bestMatch = null;
        $bestScore = 0;
        
        // Prepare tracking data in format expected by MatchingService
        $trackingFormatted = $this->formatTrackingDataForMatching($this->messageData, $datasetId);
        
        foreach ($unmatchedVideos as $video) {
            $videoData = is_string($video->message_content) 
                ? json_decode($video->message_content, true) 
                : $video->message_content;
            
            // Format video data for matching service
            $videoFormatted = $this->formatVideoDataForMatching($videoData, $video->video_id);
            
            $result = $matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
            
            // Skip early exits and require minimum 65 score
            if (isset($result['early_exit']) && $result['early_exit']) {
                continue;
            }
            
            if ($result['score'] >= 65 && $result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestMatch = [
                    'video' => $video,
                    'video_data' => $videoData,
                    'match_result' => $result
                ];
            }
        }
        
        if ($bestMatch) {
            $this->createGlobalMatchWithScore(
                $datasetId, 
                $bestMatch['video_data'],
                $bestMatch['video']->video_id,
                $bestMatch['match_result']
            );
            
            if ($bestMatch['match_result']['score'] >= 80) {
                $primeplayTeamId = $this->extractPrimeplayTeamId($this->messageData);
                $videoTeamId = $this->extractVideoTeamId($bestMatch['video_data']);
                
                if ($primeplayTeamId && $videoTeamId) {
                    ConfirmedMatch::updateOrCreate(
                        [
                            'video_team_id' => $videoTeamId,
                            'primeplay_team_id' => $primeplayTeamId
                        ],
                        [
                            'match_score' => $bestMatch['match_result']['score'],
                            'match_details' => $bestMatch['match_result']['breakdown'] ?? [],
                            'matched_at' => now()
                        ]
                    );
                    Log::info('High-score team match stored in confirmed_matches', [
                        'primeplay_team_id' => $primeplayTeamId,
                        'video_team_id' => $videoTeamId,
                        'score' => $bestMatch['match_result']['score']
                    ]);
                }
            }
            
            $bestMatch['video']->delete();
            
            Log::info('Similarity match found and created', [
                'dataset_id' => $datasetId,
                'video_id' => $bestMatch['video']->video_id,
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
    protected function createGlobalMatchWithScore($datasetId, array $videoData, $videoId, array $matchResult): void
    {
        DB::transaction(function () use ($datasetId, $videoData, $videoId, $matchResult) {
            GlobalMatches::create([
                'global_match_id' => "match_{$datasetId}_{$videoId}_" . now()->timestamp,
                'tracking_id' => $datasetId,
                'video_id' => $videoId,
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'match_details' => [
                    'match_type' => 'similarity_matched',
                    'matched_by' => 'system',
                    'match_criteria' => 'similarity_algorithm',
                    'reasons' => $matchResult['reasons'] ?? [],
                ],
                'tracking_data' => $this->messageData,
                'video_data' => $videoData,
                'status' => $matchResult['score'] >= 85 ? 'confirmed' : 'pending_review',
                'processed_by' => 'system',
                'matched_at' => now(),
            ]);
            
            // Remove from cache
            Cache::forget("primeplay:match:{$datasetId}");
            Cache::forget("video:match:{$datasetId}");
            
            // Delete temporary records
            TrackingDashboard::where('tracking_id', $datasetId)->delete();
            VideoDashboard::where('video_id', $videoId)->delete();
        });
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
        TrackingDashboard::updateOrCreate(
            ['tracking_id' => $datasetId],
            [
                'event_date' => $eventDate ?? now()->format('Y-m-d'),
                'status' => 'unmatched',
                'message_content' => $this->messageData,
                'source_system' => 'primeplay',
                'dataset_name' => $matchData['name'] ?? null,
                'team_name' => $matchData['teamName'] ?? null,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'received_at' => now(),
                'expires_at' => now()->addDays(7),
            ]
        );
        
        // Store in cache with 24h TTL
        $cacheKey = "primeplay:match:{$datasetId}";
        Cache::put($cacheKey, $this->messageData, now()->addHours(24));
        
        Log::info('Tracking data stored temporarily', [
            'tracking_id' => $datasetId,
            'event_date' => $eventDate,
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
        
        // Extract fields from message data
        $matchData = $this->messageData['match'] ?? [];
        $homeClub = $matchData['home']['name'] ?? null;
        $awayClub = $matchData['away']['name'] ?? null;
        $startTime = $matchData['starting_at']['date'] ?? null;
        $endTime = $matchData['stopping_at']['date'] ?? null;
        
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
        
        // Store in database
        VideoDashboard::create([
            'video_id' => $videoId,
            'event_date' => $eventDate ?? now()->format('Y-m-d'),
            'status' => 'unmatched',
            'message_content' => $this->messageData,
            'source_system' => 'primeplay',
            'home_club_name' => $homeClub,
            'away_club_name' => $awayClub,
            'field_name' => $matchData['field']['name'] ?? null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_minutes' => $durationMinutes,
            'is_training' => $matchData['is_training'] ?? false,
            'received_at' => now(),
            'expires_at' => now()->addDays(7),
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
            $trackingId = $this->messageData['datasetId'] ?? $this->messageData['sessionId'] ?? ('unknown_' . now()->timestamp);
            
            TrackingDashboard::updateOrCreate(
                ['tracking_id' => $trackingId],
                [
                    'message_content' => $this->messageData,
                    'source_system' => 'primeplay',
                    'received_at' => now(),
                ]
            );
            
        } catch (\Exception $e) {
            Log::error('Failed to store Primeplay message in database', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
        }
    }

    protected function extractPrimeplayTeamId(array $trackingData): ?string
    {
        $matchData = $trackingData['matchData'] ?? $trackingData;
        return $matchData['teamId'] ?? $matchData['team_id'] ?? null;
    }

    protected function extractVideoTeamId(array $videoData): ?string
    {
        $match = $videoData['match_data']['match'] ?? $videoData['match'] ?? $videoData;
        return $match['home_team']['id'] ?? $match['home']['id'] ?? null;
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
