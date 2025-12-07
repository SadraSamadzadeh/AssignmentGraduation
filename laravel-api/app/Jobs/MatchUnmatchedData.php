<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use App\Models\TeamMapping;
use App\Models\MatchHistory;
use App\Services\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MatchUnmatchedData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    /**
     * Enhanced matching process with date-based pre-filtering
     * Runs every 15 minutes to match video data with tracking data
     */
    public function handle(): void
    {
        Log::info('Starting automated matching process (video → tracking)');
        
        $matchCount = 0;
        $skippedLowScore = 0;
        $skippedEarlyExit = 0;
        $matchingService = new MatchingService();
        
        // Get all unmatched video data (source)
        $unmatchedVideos = VideoDashboard::get();
        
        // Get all unmatched tracking data (target)
        $unmatchedTracking = TrackingDashboard::where('status', 'unmatched')
            ->orWhereNull('status')
            ->get();
            
        Log::info('Found unmatched records', [
            'video_count' => $unmatchedVideos->count(),
            'tracking_count' => $unmatchedTracking->count()
        ]);
        
        // Group tracking by date for efficient lookup
        $trackingByDate = $this->groupTrackingByDate($unmatchedTracking);
        
        // For each video, find best matching tracking using similarity
        foreach ($unmatchedVideos as $video) {
            $videoTeamId = $this->extractVideoTeamId($video->video_data);
            
            if ($videoTeamId) {
                $confirmedMatch = TeamMapping::where('video_team_id', $videoTeamId)
                    ->where('status', 'active')
                    ->first();
                    
                if ($confirmedMatch) {
                    $tracking = $unmatchedTracking->first(function($t) use ($confirmedMatch) {
                        $primeplayTeamId = $this->extractPrimeplayTeamId($t->tracking_data);
                        return $primeplayTeamId === $confirmedMatch->primeplay_team_id;
                    });
                        
                    if ($tracking) {
                        $this->createMatch(
                            $tracking,
                            $video,
                            $tracking->tracking_data,
                            $video->video_data,
                            ['score' => $confirmedMatch->confidence_score, 'breakdown' => $confirmedMatch->match_details]
                        );
                        $matchCount++;
                        Log::info('Match created from confirmed team mapping', [
                            'video_team_id' => $videoTeamId,
                            'primeplay_team_id' => $confirmedMatch->primeplay_team_id,
                            'score' => $confirmedMatch->confidence_score
                        ]);
                        continue;
                    }
                }
            }
            
            $videoFormatted = $this->formatVideoData($video->video_data, $video);
            
            // Use extracted date from database instead of JSON parsing
            $videoDate = $video->event_date ? $video->event_date->format('Y-m-d') : null;
            
            // Get candidate tracking records from same day and adjacent days
            $candidates = $this->getCandidateTracking($trackingByDate, $videoDate);
            
            Log::info('Checking candidates for video', [
                'video_id' => $video->video_id,
                'video_date' => $videoDate,
                'candidate_count' => count($candidates)
            ]);
            
            if (empty($candidates)) {
                Log::info('No candidate tracking found for video', [
                    'video_id' => $video->video_id,
                    'video_date' => $videoDate
                ]);
                continue;
            }
            
            $bestMatch = null;
            $bestScore = 0;
            
            // Compare video against pre-filtered candidates only
            foreach ($candidates as $tracking) {
                $trackingFormatted = $this->formatTrackingData($tracking->tracking_data, $tracking);
                
                $result = $matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
                
                Log::info('Similarity comparison result', [
                    'video_id' => $video->video_id,
                    'tracking_id' => $tracking->tracking_id,
                    'score' => $result['score'],
                    'confidence' => $result['confidence'],
                    'early_exit' => $result['early_exit'] ?? false,
                    'breakdown' => $result['breakdown'] ?? []
                ]);
                
                // Skip if early exit (time difference too large)
                if (isset($result['early_exit']) && $result['early_exit']) {
                    $skippedEarlyExit++;
                    continue;
                }
                
                // Minimum threshold of 65 for scheduled matching
                if ($result['score'] >= 65 && $result['score'] > $bestScore) {
                    $bestScore = $result['score'];
                    $bestMatch = [
                        'tracking' => $tracking,
                        'tracking_data' => $tracking->tracking_data,
                        'result' => $result
                    ];
                    
                    if ($result['score'] >= 80) {
                        $videoTeamId = $this->extractVideoTeamId($video->video_data);
                        $primeplayTeamId = $this->extractPrimeplayTeamId($tracking->tracking_data);
                        
                        if ($videoTeamId && $primeplayTeamId) {
                            TeamMapping::updateOrCreate(
                                [
                                    'video_team_id' => $videoTeamId,
                                    'primeplay_team_id' => $primeplayTeamId
                                ],
                                [
                                    'match_score' => $result['score'],
                                    'match_details' => $result['breakdown'] ?? [],
                                    'matched_at' => now()
                                ]
                            );
                        }
                    }
                }
            }
            
            if ($bestMatch) {
                try {
                    $this->createMatch(
                        $bestMatch['tracking'], 
                        $video, 
                        $bestMatch['tracking_data'], 
                        $video->video_data, 
                        $bestMatch['result']
                    );
                    $matchCount++;
                    
                    Log::info('Automated match created', [
                        'video_id' => $video->video_id,
                        'tracking_id' => $bestMatch['tracking']->tracking_id,
                        'score' => $bestMatch['result']['score'],
                        'confidence' => $bestMatch['result']['confidence'],
                        'breakdown' => $bestMatch['result']['breakdown'] ?? []
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create automated match', [
                        'video_id' => $video->video_id,
                        'tracking_id' => $bestMatch['tracking']->tracking_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $skippedLowScore++;
            }
        }
        
        Log::info('Automated matching process completed', [
            'matches_created' => $matchCount,
            'videos_processed' => $unmatchedVideos->count(),
            'tracking_records_available' => $unmatchedTracking->count(),
            'skipped_low_score' => $skippedLowScore,
            'skipped_early_exit' => $skippedEarlyExit
        ]);
    }
    
    /**
     * Group tracking records by date for efficient lookup
     */
    protected function groupTrackingByDate($trackingRecords): array
    {
        $grouped = [];
        
        foreach ($trackingRecords as $tracking) {
            // Use extracted event_date from database instead of JSON parsing
            $date = $tracking->event_date ? $tracking->event_date->format('Y-m-d') : null;
            
            if ($date) {
                if (!isset($grouped[$date])) {
                    $grouped[$date] = [];
                }
                $grouped[$date][] = $tracking;
            }
        }
        
        return $grouped;
    }
    
    /**
     * Extract date string (Y-m-d) from datetime
     */
    protected function extractDate(?string $datetime): ?string
    {
        if (!$datetime) return null;
        
        try {
            $dt = new \DateTime($datetime);
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get candidate tracking records from same day and adjacent days
     */
    protected function getCandidateTracking(array $trackingByDate, ?string $videoDate): array
    {
        if (!$videoDate) {
            // If no date available, return all tracking (fallback)
            return array_merge(...array_values($trackingByDate));
        }
        
        $candidates = [];
        
        try {
            $date = new \DateTime($videoDate);
            
            // Include same day
            if (isset($trackingByDate[$videoDate])) {
                $candidates = array_merge($candidates, $trackingByDate[$videoDate]);
            }
            
            // Include previous day
            $prevDay = (clone $date)->modify('-1 day')->format('Y-m-d');
            if (isset($trackingByDate[$prevDay])) {
                $candidates = array_merge($candidates, $trackingByDate[$prevDay]);
            }
            
            // Include next day
            $nextDay = (clone $date)->modify('+1 day')->format('Y-m-d');
            if (isset($trackingByDate[$nextDay])) {
                $candidates = array_merge($candidates, $trackingByDate[$nextDay]);
            }
            
        } catch (\Exception $e) {
            // Fallback to all tracking if date parsing fails
            $candidates = array_merge(...array_values($trackingByDate));
        }
        
        return $candidates;
    }
    
    /**
     * Format tracking data for MatchingService
     * Use extracted database columns for accuracy (they are validated and normalized)
     */
    protected function formatTrackingData(array $trackingData, $tracking): array
    {
        // Use database extracted columns (validated and normalized)
        return [
            'id' => $tracking->tracking_id,
            'startTime' => $tracking->start_time ? $tracking->start_time->toIso8601String() : now()->toIso8601String(),
            'endTime' => $tracking->end_time ? $tracking->end_time->toIso8601String() : now()->toIso8601String(),
            'teamName' => $tracking->team_name ?? '',
        ];
    }
    
    /**
     * Format video data for MatchingService
     * Use extracted database columns for accuracy (they are validated and normalized)
     */
    protected function formatVideoData(array $videoData, $video): array
    {
        // Use database extracted columns (validated and normalized)
        $formatted = [
            'id' => $video->video_id,
            'starting_at' => [
                'date' => $video->start_time ? $video->start_time->toIso8601String() : now()->toIso8601String()
            ],
            'stopping_at' => [
                'date' => $video->end_time ? $video->end_time->toIso8601String() : now()->toIso8601String()
            ],
            'home_club' => ['name' => $video->home_club_name ?? ''],
            'away_club' => ['name' => $video->away_club_name ?? ''],
        ];
        
        return $formatted;
    }
    
    /**
     * Create a global match record
     */
    protected function createMatch($tracking, $video, array $trackingData, array $videoData, array $matchResult): void
    {
        DB::transaction(function () use ($tracking, $video, $trackingData, $videoData, $matchResult) {
            $match = GlobalMatches::create([
                'global_match_id' => "match_{$tracking->tracking_id}_{$video->video_id}_" . now()->timestamp,
                'tracking_id' => $tracking->tracking_id,
                'video_id' => $video->video_id,
                'tracking_data' => $trackingData,
                'video_data' => $videoData,
                'status' => $matchResult['score'] >= 85 ? 'confirmed' : 'pending_review',
                'confidence_level' => $matchResult['confidence'],
                'match_score' => $matchResult['score'],
                'time_proximity_score' => $matchResult['breakdown']['time_proximity_score'] ?? null,
                'duration_similarity_score' => $matchResult['breakdown']['duration_similarity_score'] ?? null,
                'temporal_overlap_score' => $matchResult['breakdown']['temporal_overlap_score'] ?? null,
                'match_details' => [
                    'match_type' => 'scheduled_matching',
                    'matched_by' => 'system',
                    'match_criteria' => 'similarity_algorithm',
                    'matched_at_job' => 'MatchUnmatchedData',
                    'reasons' => $matchResult['reasons'] ?? [],
                    'breakdown' => $matchResult['breakdown'] ?? [],
                ],
                'matched_at' => now(),
            ]);
            
            // Create match history entry
            MatchHistory::create([
                'global_match_id' => $match->id,
                'action' => 'created',
                'previous_status' => null,
                'new_status' => $match->status,
                'previous_score' => null,
                'new_score' => $match->match_score,
                'changes' => [
                    'match_type' => 'scheduled_matching',
                    'breakdown' => $matchResult['breakdown'] ?? [],
                ],
                'reason' => 'Automated match created by scheduled job',
                'performed_by_user_id' => null,
                'performed_at' => now(),
            ]);
            
            // Update status and delete records
            $tracking->update(['status' => 'matched']);
            $video->delete();
            
            // Clean up cache
            Cache::forget("primeplay:match:{$tracking->tracking_id}");
            Cache::forget("video:match:{$tracking->tracking_id}");
        });
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
}
