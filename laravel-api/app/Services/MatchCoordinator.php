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

class MatchCoordinator
{
    protected MatchingService $matchingService;
    
    const THRESHOLD_IMMEDIATE = 70;
    const THRESHOLD_BATCH = 65;
    const THRESHOLD_HIGH_CONFIDENCE = 80;
    const CANDIDATE_DATE_RANGE_DAYS = 3;
    
    public function __construct(MatchingService $matchingService)
    {
        $this->matchingService = $matchingService;
    }

    public function matchTrackingToVideos(TrackingDashboard $tracking): ?GlobalMatches
    {
        Log::info('Attempting immediate tracking→video match', [
            'tracking_id' => $tracking->tracking_id,
            'event_date' => $tracking->event_date?->format('Y-m-d')
        ]);
        
        $candidates = $this->getCandidateVideos($tracking->event_date);
        
        if ($candidates->isEmpty()) {
            Log::info('No candidate videos found for immediate matching', [
                'tracking_id' => $tracking->tracking_id
            ]);
            return null;
        }
        
        $trackingFormatted = $this->formatTrackingData($tracking->tracking_data, $tracking);
        $bestMatch = $this->findBestVideoMatch($trackingFormatted, $candidates);
        
        if (!$bestMatch || $bestMatch['score'] < self::THRESHOLD_IMMEDIATE) {
            Log::info('No suitable video match found', [
                'tracking_id' => $tracking->tracking_id,
                'best_score' => $bestMatch['score'] ?? 0
            ]);
            return null;
        }
        
        return $this->createMatch(
            $tracking,
            $bestMatch['video'],
            $tracking->tracking_data,
            $bestMatch['video']->video_data,
            $bestMatch['result']
        );
    }

    public function matchVideoToTracking(VideoDashboard $video): ?GlobalMatches
    {
        Log::info('Attempting immediate video→tracking match', [
            'video_id' => $video->video_id,
            'event_date' => $video->event_date?->format('Y-m-d')
        ]);
        
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
        
        $candidates = $this->getCandidateTracking($video->event_date);
        
        if ($candidates->isEmpty()) {
            Log::info('No candidate tracking found', [
                'video_id' => $video->video_id
            ]);
            return null;
        }
        
        $videoFormatted = $this->formatVideoData($video->video_data, $video);
        $bestMatch = $this->findBestTrackingMatch($videoFormatted, $candidates);
        
        if (!$bestMatch || $bestMatch['score'] < self::THRESHOLD_IMMEDIATE) {
            Log::info('No suitable tracking match found', [
                'video_id' => $video->video_id,
                'best_score' => $bestMatch['score'] ?? 0
            ]);
            return null;
        }
        
        return $this->createMatch(
            $bestMatch['tracking'],
            $video,
            $bestMatch['tracking']->tracking_data,
            $video->video_data,
            $bestMatch['result']
        );
    }

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
        
        $cutoffDate = now()->subDays(30);
        
        $unmatchedVideos = VideoDashboard::where(function($q) {
                $q->where('status', 'unmatched')->orWhereNull('status');
            })
            ->where('event_date', '>=', $cutoffDate)
            ->orderBy('event_date', 'desc')
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
            'cutoff_date' => $cutoffDate->format('Y-m-d')
        ]);
        
        $stats['tracking_pool_size'] = $unmatchedTracking->count();
        
        if ($unmatchedVideos->isEmpty() || $unmatchedTracking->isEmpty()) {
            Log::info('No records to match in batch process');
            return $stats;
        }
        
        $trackingByDate = $this->groupTrackingByDate($unmatchedTracking);
        
        foreach ($unmatchedVideos as $video) {
            $stats['videos_processed']++;
            
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
                
                Log::info('Match created from team mapping', [
                    'video_id' => $video->video_id,
                    'tracking_id' => $teamMatch['tracking']->tracking_id,
                    'score' => $teamMatch['result']['score']
                ]);
                continue;
            }
            
            $videoFormatted = $this->formatVideoData($video->video_data, $video);
            $videoDate = $video->event_date ? $video->event_date->format('Y-m-d') : null;
            
            $candidates = $this->getCandidateTrackingFromGrouped($trackingByDate, $videoDate);
            
            if (empty($candidates)) {
                Log::debug('No candidates for video', ['video_id' => $video->video_id]);
                continue;
            }
            
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

    protected function getCandidateVideos(?\DateTime $date)
    {
        if (!$date) {
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
            ->orderBy('event_date')
            ->get();
    }

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
                  ->orderBy('event_date');
        } else {
            $start = now()->subDays(7);
            $end = now()->addDay();
            $query->whereBetween('event_date', [$start, $end]);
        }
        
        return $query->get();
    }

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

    protected function formatTrackingData(array $trackingData, TrackingDashboard $tracking): array
    {
        return [
            'id' => $tracking->tracking_id,
            'startTime' => $tracking->start_time ? $tracking->start_time->toIso8601String() : now()->toIso8601String(),
            'endTime' => $tracking->end_time ? $tracking->end_time->toIso8601String() : now()->toIso8601String(),
            'teamName' => $tracking->team_name ?? '',
        ];
    }

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
