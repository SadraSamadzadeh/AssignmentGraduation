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
            $videoData = is_string($video->message_content) ? json_decode($video->message_content, true) : $video->message_content;
            $videoTeamId = $this->extractVideoTeamId($videoData);
            
            if ($videoTeamId) {
                $confirmedMatch = ConfirmedMatch::where('video_team_id', $videoTeamId)->first();
                if ($confirmedMatch) {
                    $tracking = $unmatchedTracking->first(function($t) use ($confirmedMatch) {
                        $trackingData = is_string($t->message_content) ? json_decode($t->message_content, true) : $t->message_content;
                        $primeplayTeamId = $this->extractPrimeplayTeamId($trackingData);
                        return $primeplayTeamId === $confirmedMatch->primeplay_team_id;
                    });
                        
                    if ($tracking) {
                        $trackingData = is_string($tracking->message_content) ? json_decode($tracking->message_content, true) : $tracking->message_content;
                        $this->createMatch(
                            $tracking,
                            $video,
                            $trackingData,
                            $videoData,
                            ['score' => $confirmedMatch->match_score, 'breakdown' => $confirmedMatch->match_details]
                        );
                        $matchCount++;
                        Log::info('Match created from confirmed team match', [
                            'video_team_id' => $videoTeamId,
                            'primeplay_team_id' => $confirmedMatch->primeplay_team_id,
                            'score' => $confirmedMatch->match_score
                        ]);
                        continue;
                    }
                }
            }
            
            $videoData = is_string($video->message_content) 
                ? json_decode($video->message_content, true) 
                : $video->message_content;
            
            $videoFormatted = $this->formatVideoData($videoData, $video);
            
            // Extract video date for pre-filtering
            $videoDate = $this->extractDate($videoFormatted['starting_at']['date']);
            
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
                $trackingData = is_string($tracking->message_content) 
                    ? json_decode($tracking->message_content, true) 
                    : $tracking->message_content;
                
                $trackingFormatted = $this->formatTrackingData($trackingData, $tracking);
                
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
                        'tracking_data' => $trackingData,
                        'result' => $result
                    ];
                    
                    if ($result['score'] >= 80) {
                        $videoTeamId = $this->extractVideoTeamId($videoData);
                        $primeplayTeamId = $this->extractPrimeplayTeamId($trackingData);
                        
                        if ($videoTeamId && $primeplayTeamId) {
                            ConfirmedMatch::updateOrCreate(
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
                        $videoData, 
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
            $trackingData = is_string($tracking->message_content) 
                ? json_decode($tracking->message_content, true) 
                : $tracking->message_content;
            
            $date = $this->extractDate(
                $trackingData['matchData']['start'] 
                ?? $trackingData['matchData']['startTime'] 
                ?? null
            );
            
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
        $matchData = $trackingData['matchData'] ?? $trackingData;
        
        // Prefer database columns over JSONB data for time values
        // as database columns are validated and normalized during extraction
        return [
            'id' => $tracking->tracking_id,
            'startTime' => $tracking->start_time ?? $matchData['start'] ?? $matchData['startTime'] ?? now()->toIso8601String(),
            'endTime' => $tracking->end_time ?? $matchData['end'] ?? $matchData['endTime'] ?? now()->toIso8601String(),
            'teamName' => $tracking->team_name ?? $matchData['teamName'] ?? $matchData['name'] ?? '',
        ];
    }
    
    /**
     * Format video data for MatchingService
     * Use extracted database columns for accuracy (they are validated and normalized)
     */
    protected function formatVideoData(array $videoData, $video): array
    {
        // Extract match data from the nested structure
        $match = $videoData['match_data'] ?? $videoData['match'] ?? $videoData;
        
        // Extract date strings, handle both array and string formats
        $startingAt = $match['starting_at'] ?? $match['atom_starting_at'] ?? now()->toIso8601String();
        $stoppingAt = $match['stopping_at'] ?? $match['atom_stopping_at'] ?? now()->toIso8601String();
        
        // If starting_at is an array with a 'date' key, extract it
        if (is_array($startingAt) && isset($startingAt['date'])) {
            $startingAt = $startingAt['date'];
        }
        if (is_array($stoppingAt) && isset($stoppingAt['date'])) {
            $stoppingAt = $stoppingAt['date'];
        }
        
        // Prefer database columns for time values (validated and normalized)
        $startingAt = $video->start_time ?? $startingAt;
        $stoppingAt = $video->end_time ?? $stoppingAt;
        
        // Log for debugging
        Log::debug('Formatting video data', [
            'video_id' => $video->video_id,
            'has_match_data' => isset($videoData['match_data']),
            'starting_at' => $startingAt,
            'home_name' => $video->home_club_name ?? $match['home']['name'] ?? 'MISSING',
            'away_name' => $video->away_club_name ?? $match['away']['name'] ?? 'MISSING',
        ]);
        
        return [
            'id' => $video->video_id,
            'starting_at' => [
                'date' => $startingAt,
            ],
            'stopping_at' => [
                'date' => $stoppingAt,
            ],
            'timezone' => $match['timezone'] ?? 'UTC',
            'home' => [
                'name' => $video->home_club_name ?? $match['home_team']['name'] ?? $match['home']['name'] ?? '',
            ],
            'away' => [
                'name' => $video->away_club_name ?? $match['away_team']['name'] ?? $match['away']['name'] ?? '',
            ],
            'club' => [
                'name' => $match['club']['name'] ?? $match['home_club']['name'] ?? '',
            ],
        ];
    }
    
    /**
     * Create a global match record
     */
    protected function createMatch($tracking, $video, array $trackingData, array $videoData, array $matchResult): void
    {
        DB::transaction(function () use ($tracking, $video, $trackingData, $videoData, $matchResult) {
            GlobalMatches::create([
                'global_match_id' => "match_{$tracking->tracking_id}_{$video->video_id}_" . now()->timestamp,
                'tracking_id' => $tracking->tracking_id,
                'video_id' => $video->video_id,
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'match_details' => [
                    'match_type' => 'scheduled_matching',
                    'matched_by' => 'system',
                    'match_criteria' => 'similarity_algorithm',
                    'matched_at_job' => 'MatchUnmatchedData',
                    'reasons' => $matchResult['reasons'] ?? [],
                ],
                'tracking_data' => $trackingData,
                'video_data' => $videoData,
                'status' => $matchResult['score'] >= 85 ? 'confirmed' : 'pending_review',
                'processed_by' => 'scheduled_job',
                'matched_at' => now(),
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
