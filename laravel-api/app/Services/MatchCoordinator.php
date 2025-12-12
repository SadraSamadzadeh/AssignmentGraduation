<?php

namespace App\Services;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use App\Models\TeamMapping;
use App\Models\MatchHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralized matching coordinator service
 * Handles all matching logic: immediate (on ingestion) and batch (scheduled)
 */
class MatchCoordinator
{
    protected MatchingService $matchingService;
    
    // Matching thresholds
    const THRESHOLD_IMMEDIATE = 70;  // Higher bar for immediate matching
    const THRESHOLD_BATCH = 65;      // Slightly lower for batch retries
    const THRESHOLD_HIGH_CONFIDENCE = 80; // For auto team mapping
    
    // Candidate filtering window (performance optimization)
    const CANDIDATE_DATE_RANGE_DAYS = 3;  // ±3 days for candidate filtering
    
    public function __construct(MatchingService $matchingService)
    {
        $this->matchingService = $matchingService;
    }

    /**
     * Attempt immediate match for newly ingested tracking
     * Used by: ProcessPrimeplayMessage
     * Strategy: Match single tracking against all available videos
     */
    public function matchTrackingToVideos(TrackingDashboard $tracking): ?GlobalMatches
    {
        Log::info('Attempting immediate tracking→video match', [
            'tracking_id' => $tracking->tracking_id,
            'event_date' => $tracking->event_date?->format('Y-m-d')
        ]);
        
        // Get candidate videos (±3 days for performance optimization)
        $candidates = $this->getCandidateVideos($tracking->event_date);
        
        if ($candidates->isEmpty()) {
            Log::info('No candidate videos found for immediate matching', [
                'tracking_id' => $tracking->tracking_id,
                'date_range' => '±' . self::CANDIDATE_DATE_RANGE_DAYS . ' days'
            ]);
            return null;
        }
        
        $trackingFormatted = $this->formatTrackingData($tracking->tracking_data, $tracking);
        
        // Find best match among candidates
        $bestMatch = $this->findBestVideoMatch($trackingFormatted, $candidates);
        
        if (!$bestMatch || $bestMatch['score'] < self::THRESHOLD_IMMEDIATE) {
            Log::info('No suitable video match found (below threshold)', [
                'tracking_id' => $tracking->tracking_id,
                'best_score' => $bestMatch['score'] ?? 0,
                'threshold' => self::THRESHOLD_IMMEDIATE
            ]);
            return null;
        }
        
        // Create the match
        return $this->createMatch(
            $tracking,
            $bestMatch['video'],
            $tracking->tracking_data,
            $bestMatch['video']->video_data,
            $bestMatch['result']
        );
    }

    /**
     * Attempt immediate match for newly ingested video
     * Used by: ProcessVideoMessage
     * Strategy: Match single video against all available tracking
     */
    public function matchVideoToTracking(VideoDashboard $video): ?GlobalMatches
    {
        Log::info('Attempting immediate video→tracking match', [
            'video_id' => $video->video_id,
            'event_date' => $video->event_date?->format('Y-m-d')
        ]);
        
        // Check team mapping first (fastest path)
        $teamMatch = $this->checkTeamMappingForVideo($video);
        if ($teamMatch) {
            return $this->createMatch(
                $teamMatch['tracking'],
                $video,
                $teamMatch['tracking']->tracking_data,
                $video->video_data,
                $teamMatch['result']
            );
        }
        
        // Get candidate tracking (±3 days for performance optimization)
        $candidates = $this->getCandidateTracking($video->event_date);
        
        if ($candidates->isEmpty()) {
            Log::info('No candidate tracking found for immediate matching', [
                'video_id' => $video->video_id,
                'date_range' => '±' . self::CANDIDATE_DATE_RANGE_DAYS . ' days'
            ]);
            return null;
        }
        
        $videoFormatted = $this->formatVideoData($video->video_data, $video);
        
        // Find best match among candidates
        $bestMatch = $this->findBestTrackingMatch($videoFormatted, $candidates);
        
        if (!$bestMatch || $bestMatch['score'] < self::THRESHOLD_IMMEDIATE) {
            Log::info('No suitable tracking match found (below threshold)', [
                'video_id' => $video->video_id,
                'best_score' => $bestMatch['score'] ?? 0,
                'threshold' => self::THRESHOLD_IMMEDIATE
            ]);
            return null;
        }
        
        // Create the match
        return $this->createMatch(
            $bestMatch['tracking'],
            $video,
            $bestMatch['tracking']->tracking_data,
            $video->video_data,
            $bestMatch['result']
        );
    }

    /**
     * Batch process all unmatched records
     * Used by: MatchUnmatchedData (scheduled job)
     * Strategy: Process unmatched videos against unmatched tracking within date range
     * 
     * Optimization: Only fetch recent unmatched records (last 30 days) to avoid
     * processing very old data that's unlikely to match
     */
    public function processBatchMatching(): array
    {
        Log::info('Starting batch matching process');
        
        $stats = [
            'matches_created' => 0,
            'skipped_low_score' => 0,
            'skipped_early_exit' => 0,
            'team_mappings_created' => 0,
            'videos_processed' => 0,
            'tracking_pool_size' => 0,
        ];
        
        // Fetch unmatched records from last 30 days only (performance optimization)
        $cutoffDate = now()->subDays(30);
        
        $unmatchedVideos = VideoDashboard::where(function($q) {
                $q->where('status', 'unmatched')->orWhereNull('status');
            })
            ->where('event_date', '>=', $cutoffDate)
            ->orderBy('event_date', 'desc')  // Process newest first
            ->get();
            
        $unmatchedTracking = TrackingDashboard::where(function($q) {
                $q->where('status', 'unmatched')->orWhereNull('status');
            })
            ->where('event_date', '>=', $cutoffDate)
            ->orderBy('event_date', 'desc')
            ->get();
            
        Log::info('Found unmatched records for batch processing', [
            'video_count' => $unmatchedVideos->count(),
            'tracking_count' => $unmatchedTracking->count(),
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'date_range_per_video' => '±' . self::CANDIDATE_DATE_RANGE_DAYS . ' days'
        ]);
        
        $stats['tracking_pool_size'] = $unmatchedTracking->count();
        
        if ($unmatchedVideos->isEmpty() || $unmatchedTracking->isEmpty()) {
            Log::info('No records to match in batch process');
            return $stats;
        }
        
        // Group tracking by date for efficient lookup (±3 days per video)
        $trackingByDate = $this->groupTrackingByDate($unmatchedTracking);
        
        // Process each video
        foreach ($unmatchedVideos as $video) {
            $stats['videos_processed']++;
            
            // Check team mapping first (confirmed matches)
            $teamMatch = $this->checkTeamMappingForVideo($video, $unmatchedTracking);
            
            if ($teamMatch) {
                $this->createMatch(
                    $teamMatch['tracking'],
                    $video,
                    $teamMatch['tracking']->tracking_data,
                    $video->video_data,
                    $teamMatch['result']
                );
                $stats['matches_created']++;
                
                Log::info('Match created from confirmed team mapping', [
                    'video_id' => $video->video_id,
                    'tracking_id' => $teamMatch['tracking']->tracking_id,
                    'score' => $teamMatch['result']['score']
                ]);
                continue;
            }
            
            // Similarity matching
            $videoFormatted = $this->formatVideoData($video->video_data, $video);
            $videoDate = $video->event_date ? $video->event_date->format('Y-m-d') : null;
            
            // Get date-filtered candidates
            $candidates = $this->getCandidateTrackingFromGrouped($trackingByDate, $videoDate);
            
            if (empty($candidates)) {
                Log::debug('No candidates for video', ['video_id' => $video->video_id]);
                continue;
            }
            
            // Find best match
            $bestMatch = $this->findBestTrackingMatchFromArray($videoFormatted, $candidates);
            
            if (!$bestMatch) {
                $stats['skipped_low_score']++;
                continue;
            }
            
            if ($bestMatch['result']['early_exit'] ?? false) {
                $stats['skipped_early_exit']++;
                continue;
            }
            
            if ($bestMatch['score'] >= self::THRESHOLD_BATCH) {
                $this->createMatch(
                    $bestMatch['tracking'],
                    $video,
                    $bestMatch['tracking']->tracking_data,
                    $video->video_data,
                    $bestMatch['result']
                );
                $stats['matches_created']++;
                
                // Auto-create team mapping if high confidence
                if ($bestMatch['score'] >= self::THRESHOLD_HIGH_CONFIDENCE) {
                    if ($this->updateTeamMapping($video, $bestMatch['tracking'], $bestMatch['result'])) {
                        $stats['team_mappings_created']++;
                    }
                }
                
                Log::info('Batch match created', [
                    'video_id' => $video->video_id,
                    'tracking_id' => $bestMatch['tracking']->tracking_id,
                    'score' => $bestMatch['score']
                ]);
            } else {
                $stats['skipped_low_score']++;
            }
        }
        
        Log::info('Batch matching completed', $stats);
        
        return $stats;
    }

    /**
     * Find best video match for a tracking record
     */
    protected function findBestVideoMatch(array $trackingFormatted, $videoCandidates): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($videoCandidates as $video) {
            $videoFormatted = $this->formatVideoData($video->video_data, $video);
            
            $result = $this->matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
            
            if (isset($result['early_exit']) && $result['early_exit']) {
                continue;
            }
            
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestMatch = [
                    'video' => $video,
                    'result' => $result,
                    'score' => $result['score']
                ];
            }
        }
        
        return $bestMatch;
    }

    /**
     * Find best tracking match for a video record (Collection)
     */
    protected function findBestTrackingMatch(array $videoFormatted, $trackingCandidates): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($trackingCandidates as $tracking) {
            $trackingFormatted = $this->formatTrackingData($tracking->tracking_data, $tracking);
            
            $result = $this->matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
            
            if (isset($result['early_exit']) && $result['early_exit']) {
                continue;
            }
            
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestMatch = [
                    'tracking' => $tracking,
                    'result' => $result,
                    'score' => $result['score']
                ];
            }
        }
        
        return $bestMatch;
    }

    /**
     * Find best tracking match from plain array (used in batch)
     */
    protected function findBestTrackingMatchFromArray(array $videoFormatted, array $trackingArray): ?array
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($trackingArray as $tracking) {
            $trackingFormatted = $this->formatTrackingData($tracking->tracking_data, $tracking);
            
            $result = $this->matchingService->compareTrackingAndVideo($trackingFormatted, $videoFormatted);
            
            Log::debug('Batch comparison', [
                'tracking_id' => $tracking->tracking_id,
                'score' => $result['score'],
                'early_exit' => $result['early_exit'] ?? false
            ]);
            
            if (isset($result['early_exit']) && $result['early_exit']) {
                continue;
            }
            
            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestMatch = [
                    'tracking' => $tracking,
                    'result' => $result,
                    'score' => $result['score']
                ];
            }
        }
        
        return $bestMatch;
    }

    /**
     * Get candidate videos for a tracking date (±3 days)
     * Optimization: Only query videos within date range instead of all videos
     * 
     * Performance Impact:
     * - Before: Query ALL videos (could be 100k+ records)
     * - After: Query only ±3 days (typically 100-1000 records)
     * - Speedup: 10x-100x faster at scale
     */
    protected function getCandidateVideos(?\DateTime $date)
    {
        if (!$date) {
            // Fallback: if no date, query recent videos only (last 7 days)
            $start = now()->subDays(7);
            $end = now()->addDay();
            return VideoDashboard::whereBetween('event_date', [$start, $end])->get();
        }
        
        $start = (clone $date)->modify('-' . self::CANDIDATE_DATE_RANGE_DAYS . ' days');
        $end = (clone $date)->modify('+' . self::CANDIDATE_DATE_RANGE_DAYS . ' days');
        
        Log::debug('Querying candidate videos', [
            'date_range' => $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d'),
            'center_date' => $date->format('Y-m-d')
        ]);
        
        return VideoDashboard::whereBetween('event_date', [$start, $end])
            ->orderBy('event_date')  // Use index for faster scan
            ->get();
    }

    /**
     * Get candidate tracking for a video date (±3 days)
     * Optimization: Only query unmatched tracking within date range
     * 
     * Performance Impact:
     * - Before: Query ALL unmatched tracking (could be 100k+ records)
     * - After: Query only ±3 days (typically 100-1000 records)
     * - Speedup: 10x-100x faster at scale
     */
    protected function getCandidateTracking(?\DateTime $date)
    {
        $query = TrackingDashboard::where(function($q) {
            $q->where('status', 'unmatched')->orWhereNull('status');
        });
        
        if ($date) {
            $start = (clone $date)->modify('-' . self::CANDIDATE_DATE_RANGE_DAYS . ' days');
            $end = (clone $date)->modify('+' . self::CANDIDATE_DATE_RANGE_DAYS . ' days');
            
            Log::debug('Querying candidate tracking', [
                'date_range' => $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d'),
                'center_date' => $date->format('Y-m-d')
            ]);
            
            $query->whereBetween('event_date', [$start, $end])
                  ->orderBy('event_date');  // Use index for faster scan
        } else {
            // Fallback: if no date, query recent tracking only (last 7 days)
            $start = now()->subDays(7);
            $end = now()->addDay();
            $query->whereBetween('event_date', [$start, $end]);
        }
        
        return $query->get();
    }

    /**
     * Group tracking records by date for batch processing
     */
    protected function groupTrackingByDate($trackingCollection): array
    {
        $grouped = [];
        
        foreach ($trackingCollection as $tracking) {
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
     * Get candidates from grouped tracking (±3 days)
     * Used in batch processing to efficiently filter candidates
     */
    protected function getCandidateTrackingFromGrouped(array $trackingByDate, ?string $videoDate): array
    {
        if (!$videoDate) {
            return array_merge(...array_values($trackingByDate));
        }
        
        $candidates = [];
        
        try {
            $date = new \DateTime($videoDate);
            
            // Get all dates within ±3 days range
            for ($i = -self::CANDIDATE_DATE_RANGE_DAYS; $i <= self::CANDIDATE_DATE_RANGE_DAYS; $i++) {
                $targetDate = (clone $date)->modify("{$i} days")->format('Y-m-d');
                
                if (isset($trackingByDate[$targetDate])) {
                    $candidates = array_merge($candidates, $trackingByDate[$targetDate]);
                }
            }
            
            Log::debug('Retrieved candidates from grouped tracking', [
                'video_date' => $videoDate,
                'date_range' => '±' . self::CANDIDATE_DATE_RANGE_DAYS . ' days',
                'candidate_count' => count($candidates)
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Failed to parse video date, returning all candidates', [
                'video_date' => $videoDate,
                'error' => $e->getMessage()
            ]);
            $candidates = array_merge(...array_values($trackingByDate));
        }
        
        return $candidates;
    }

    /**
     * Check if video has confirmed team mapping
     * Optionally pass tracking collection to search within (for batch)
     */
    protected function checkTeamMappingForVideo(VideoDashboard $video, $trackingCollection = null): ?array
    {
        $videoTeamId = $this->extractVideoTeamId($video->video_data);
        
        if (!$videoTeamId) {
            return null;
        }
        
        $mapping = TeamMapping::where('video_team_id', $videoTeamId)
            ->where('status', 'active')
            ->first();
            
        if (!$mapping) {
            return null;
        }
        
        // Search in provided collection or query database
        if ($trackingCollection) {
            $tracking = $trackingCollection->first(function($t) use ($mapping) {
                $primeplayTeamId = $this->extractPrimeplayTeamId($t->tracking_data);
                return $primeplayTeamId === $mapping->primeplay_team_id;
            });
        } else {
            $tracking = TrackingDashboard::where(function($q) {
                $q->where('status', 'unmatched')->orWhereNull('status');
            })->get()->first(function($t) use ($mapping) {
                $primeplayTeamId = $this->extractPrimeplayTeamId($t->tracking_data);
                return $primeplayTeamId === $mapping->primeplay_team_id;
            });
        }
        
        if (!$tracking) {
            return null;
        }
        
        return [
            'tracking' => $tracking,
            'result' => [
                'score' => $mapping->confidence_score,
                'confidence' => 'high',
                'breakdown' => $mapping->match_details,
                'reasons' => ['Confirmed team mapping']
            ]
        ];
    }

    /**
     * Update or create team mapping after high-confidence match
     */
    protected function updateTeamMapping(VideoDashboard $video, TrackingDashboard $tracking, array $result): bool
    {
        $videoTeamId = $this->extractVideoTeamId($video->video_data);
        $primeplayTeamId = $this->extractPrimeplayTeamId($tracking->tracking_data);
        
        if (!$videoTeamId || !$primeplayTeamId) {
            return false;
        }
        
        TeamMapping::updateOrCreate(
            [
                'video_team_id' => $videoTeamId,
                'primeplay_team_id' => $primeplayTeamId
            ],
            [
                'match_score' => $result['score'],
                'match_details' => $result['breakdown'] ?? [],
                'matched_at' => now(),
                'status' => 'active'
            ]
        );
        
        Log::info('Team mapping updated', [
            'video_team_id' => $videoTeamId,
            'primeplay_team_id' => $primeplayTeamId,
            'score' => $result['score']
        ]);
        
        return true;
    }

    /**
     * Create a global match with full audit trail
     */
    protected function createMatch(
        TrackingDashboard $tracking,
        VideoDashboard $video,
        array $trackingData,
        array $videoData,
        array $matchResult
    ): GlobalMatches {
        return DB::transaction(function () use ($tracking, $video, $trackingData, $videoData, $matchResult) {
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
                    'match_type' => 'automated',
                    'matched_by' => 'system',
                    'match_criteria' => 'similarity_algorithm',
                    'reasons' => $matchResult['reasons'] ?? [],
                    'breakdown' => $matchResult['breakdown'] ?? [],
                ],
                'matched_at' => now(),
            ]);
            
            // Create match history
            MatchHistory::create([
                'global_match_id' => $match->id,
                'action' => 'created',
                'previous_status' => null,
                'new_status' => $match->status,
                'previous_score' => null,
                'new_score' => $match->match_score,
                'changes' => [
                    'match_type' => 'automated',
                    'breakdown' => $matchResult['breakdown'] ?? [],
                ],
                'reason' => 'Automated match created',
                'performed_by_user_id' => null,
                'performed_at' => now(),
            ]);
            
            // Update status and clean up
            $tracking->update(['status' => 'matched']);
            $video->delete();
            
            Cache::forget("primeplay:match:{$tracking->tracking_id}");
            Cache::forget("video:match:{$video->video_id}");
            
            Log::info('Match created', [
                'match_id' => $match->global_match_id,
                'tracking_id' => $tracking->tracking_id,
                'video_id' => $video->video_id,
                'score' => $match->match_score,
                'confidence' => $match->confidence_level
            ]);
            
            return $match;
        });
    }

    /**
     * Format tracking data for MatchingService
     */
    protected function formatTrackingData(array $trackingData, TrackingDashboard $tracking): array
    {
        return [
            'id' => $tracking->tracking_id,
            'startTime' => $tracking->start_time ? $tracking->start_time->toIso8601String() : now()->toIso8601String(),
            'endTime' => $tracking->end_time ? $tracking->end_time->toIso8601String() : now()->toIso8601String(),
            'teamName' => $tracking->team_name ?? '',
        ];
    }

    /**
     * Format video data for MatchingService
     */
    protected function formatVideoData(array $videoData, VideoDashboard $video): array
    {
        return [
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
