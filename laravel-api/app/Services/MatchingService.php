<?php

namespace App\Services;

use App\Models\TeamMapping;
use DateTime;
use DateTimeZone;

class MatchingService
{
    public function compareTrackingAndVideo(array $trackingData, array $videoData): array
    {
        $score = 0;
        $reasons = [];
        $breakdown = [];
        $earlyExit = false;

        // Check team mapping first - if high-confidence mapping exists, use it directly
        $teamMappingResult = $this->checkTeamMapping($trackingData, $videoData);
        
        // If we found a high-confidence team mapping, bypass the normal algorithm
        if ($teamMappingResult['mapping_found'] && $teamMappingResult['confidence_score'] >= 80) {
            // Calculate score based on the team mapping confidence
            $mappingScore = 70 + (($teamMappingResult['confidence_score'] - 80) / 20 * 30); // Scale 80-100 to 70-100
            
            return [
                'score' => round($mappingScore, 2),
                'confidence' => $this->getConfidenceLevel($mappingScore),
                'reasons' => [
                    "Matched via high-confidence team mapping",
                    "Team mapping confidence: {$teamMappingResult['confidence_score']}/100",
                    "Previously matched {$teamMappingResult['times_matched']} times"
                ],
                'breakdown' => [
                    'team_mapping' => [
                        'used' => true,
                        'confidence_score' => $teamMappingResult['confidence_score'],
                        'times_matched' => $teamMappingResult['times_matched'],
                        'primeplay_team' => $teamMappingResult['primeplay_team'],
                        'video_team' => $teamMappingResult['video_team']
                    ]
                ],
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'early_exit' => false,
                'matched_via_team_mapping' => true
            ];
        }

        // No high-confidence team mapping found - proceed with normal algorithm
        $timeScore = $this->calculateTimeProximity($trackingData, $videoData);
        $timeWeight = 70;
        $score += $timeScore * ($timeWeight / 100);
        $reasons[] = "Time proximity: {$timeScore}/100";
        $breakdown['time_proximity'] = [
            'score' => $timeScore,
            'weight' => $timeWeight,
            'weighted_score' => round($timeScore * ($timeWeight / 100), 2)
        ];

        if ($timeScore < 40) {
            $earlyExit = true;
            return [
                'score' => 0,
                'confidence' => 'unlikely',
                'reasons' => ['Time difference too large - likely different matches'],
                'breakdown' => $breakdown,
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'early_exit' => $earlyExit
            ];
        }

        $durationScore = $this->calculateDurationSimilarity($trackingData, $videoData);
        $durationWeight = 20;
        $score += $durationScore * ($durationWeight / 100);
        $reasons[] = "Duration similarity: {$durationScore}/100";
        $breakdown['duration_similarity'] = [
            'score' => $durationScore,
            'weight' => $durationWeight,
            'weighted_score' => round($durationScore * ($durationWeight / 100), 2)
        ];

        $overlapScore = $this->calculateTemporalOverlap($trackingData, $videoData);
        $overlapWeight = 10;
        $score += $overlapScore * ($overlapWeight / 100);
        $reasons[] = "Temporal overlap: {$overlapScore}/100";
        $breakdown['temporal_overlap'] = [
            'score' => $overlapScore,
            'weight' => $overlapWeight,
            'weighted_score' => round($overlapScore * ($overlapWeight / 100), 2)
        ];

        // Add team mapping info to breakdown even if not used for matching
        $breakdown['team_mapping'] = [
            'used' => false,
            'reason' => $teamMappingResult['reason']
        ];

        return [
            'score' => round($score, 2),
            'confidence' => $this->getConfidenceLevel($score),
            'reasons' => $reasons,
            'breakdown' => $breakdown,
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id'],
            'early_exit' => $earlyExit,
            'matched_via_team_mapping' => false
        ];
    }

    /**
     * More forgiving for same-day matches, stricter for different days
     */
    private function calculateTimeProximity(array $trackingData, array $videoData): float
    {
        $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
        $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
        
        $timeDiffMinutes = abs($trackingStart->getTimestamp() - $videoStart->getTimestamp()) / 60;
        $timeDiffHours = $timeDiffMinutes / 60;
        
        // Check if same day
        $sameDay = $trackingStart->format('Y-m-d') === $videoStart->format('Y-m-d');
        
        // Enhanced scoring with day-awareness
        if ($timeDiffMinutes <= 5) return 100;      // Within 5 minutes - perfect match
        if ($timeDiffMinutes <= 15) return 95;      // Within 15 minutes - excellent
        if ($timeDiffMinutes <= 30) return 90;      // Within 30 minutes - very good
        if ($timeDiffMinutes <= 60) return 85;      // Within 1 hour - good
        
        if ($sameDay) {
            // Same day - more forgiving
            if ($timeDiffHours <= 2) return 75;     // Within 2 hours
            if ($timeDiffHours <= 4) return 60;     // Within 4 hours
            if ($timeDiffHours <= 8) return 45;     // Within 8 hours
            return 30;                              // Same day but large difference
        } else {
            // Different days - much stricter
            if ($timeDiffHours <= 24) return 40;    // Within 24 hours
            if ($timeDiffHours <= 48) return 20;    // Within 48 hours
            return 0;                               // More than 2 days - no match
        }
    }

    protected function calculateDurationSimilarity(array $trackingData, array $videoData): int
    {
        try {
            // Calculate tracking duration
            $trackingStart = new DateTime($trackingData['startTime']);
            $trackingEnd = new DateTime($trackingData['endTime']);
            $trackingDuration = ($trackingEnd->getTimestamp() - $trackingStart->getTimestamp()) / 60; // minutes
            
            // Calculate video duration
            $videoStart = new DateTime($videoData['starting_at']['date']);
            $videoEnd = new DateTime($videoData['stopping_at']['date']);
            $videoDuration = ($videoEnd->getTimestamp() - $videoStart->getTimestamp()) / 60; // minutes
            
            if ($trackingDuration <= 0 || $videoDuration <= 0) {
                return 50; // Neutral score for invalid durations
            }
            
            // Calculate percentage difference
            $maxDuration = max($trackingDuration, $videoDuration);
            $minDuration = min($trackingDuration, $videoDuration);
            $similarity = ($minDuration / $maxDuration) * 100;
            
            // Apply threshold penalties
            $diffPercent = abs($trackingDuration - $videoDuration) / $maxDuration * 100;
            
            if ($diffPercent <= 5) return 100;   // Within 5% - perfect
            if ($diffPercent <= 10) return 90;   // Within 10% - excellent
            if ($diffPercent <= 20) return 75;   // Within 20% - good
            if ($diffPercent <= 35) return 55;   // Within 35% - acceptable
            if ($diffPercent <= 50) return 35;   // Within 50% - poor
            return 15;                           // More than 50% difference
            
        } catch (\Exception $e) {
            return 50; // Neutral score on error
        }
    }

    protected function calculateTemporalOverlap(array $trackingData, array $videoData): int
    {
        try {
            $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
            $trackingEnd = $this->normalizeToUTC($trackingData['endTime']);
            $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
            $videoEnd = $this->normalizeToUTC($videoData['stopping_at']['date'], $videoData['timezone'] ?? 'UTC');
            
            // Find overlap period
            $overlapStart = max($trackingStart->getTimestamp(), $videoStart->getTimestamp());
            $overlapEnd = min($trackingEnd->getTimestamp(), $videoEnd->getTimestamp());
            
            // No overlap
            if ($overlapEnd <= $overlapStart) {
                return 0;
            }
            
            $overlapMinutes = ($overlapEnd - $overlapStart) / 60;
            $trackingDuration = ($trackingEnd->getTimestamp() - $trackingStart->getTimestamp()) / 60;
            $videoDuration = ($videoEnd->getTimestamp() - $videoStart->getTimestamp()) / 60;
            $minDuration = min($trackingDuration, $videoDuration);
            
            if ($minDuration <= 0) {
                return 0;
            }
            
            // Calculate overlap percentage
            $overlapPercent = ($overlapMinutes / $minDuration) * 100;
            
            // Score based on overlap percentage
            if ($overlapPercent >= 90) return 100;  // 90%+ overlap - perfect
            if ($overlapPercent >= 75) return 90;   // 75-90% - excellent
            if ($overlapPercent >= 60) return 75;   // 60-75% - good
            if ($overlapPercent >= 40) return 55;   // 40-60% - acceptable
            if ($overlapPercent >= 20) return 30;   // 20-40% - poor
            return 10;                               // <20% - very poor
            
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if there's a high-confidence team mapping between tracking and video teams.
     * This allows the algorithm to leverage previously learned team associations.
     */
    private function checkTeamMapping(array $trackingData, array $videoData): array
    {
        try {
            // Extract team information from tracking data
            $trackingTeamName = $trackingData['team_name'] ?? null;
            
            // Extract team information from video data (can be home or away team)
            $videoHomeTeam = $videoData['home_club_name'] ?? null;
            $videoAwayTeam = $videoData['away_club_name'] ?? null;
            
            // If we don't have team information, return neutral score
            if (!$trackingTeamName || (!$videoHomeTeam && !$videoAwayTeam)) {
                return [
                    'reason' => 'No team data available',
                    'mapping_found' => false
                ];
            }
            
            // Check for high-confidence mappings (>= 80 confidence, active status)
            // Check both home and away teams
            $mappings = TeamMapping::where('status', 'active')
                ->where('confidence_score', '>=', 80)
                ->where('primeplay_team_name', $trackingTeamName)
                ->whereIn('video_team_name', array_filter([$videoHomeTeam, $videoAwayTeam]))
                ->orderBy('confidence_score', 'desc')
                ->first();
            
            if ($mappings) {
                // Found a high-confidence mapping!
                return [
                    'reason' => "High-confidence team mapping found",
                    'mapping_found' => true,
                    'confidence_score' => $mappings->confidence_score,
                    'times_matched' => $mappings->times_matched,
                    'primeplay_team' => $trackingTeamName,
                    'video_team' => $mappings->video_team_name
                ];
            }
            
            // Check for medium-confidence mappings (60-79 confidence)
            $mediumMapping = TeamMapping::where('status', 'active')
                ->where('confidence_score', '>=', 60)
                ->where('confidence_score', '<', 80)
                ->where('primeplay_team_name', $trackingTeamName)
                ->whereIn('video_team_name', array_filter([$videoHomeTeam, $videoAwayTeam]))
                ->orderBy('confidence_score', 'desc')
                ->first();
            
            if ($mediumMapping) {
                // Found a medium-confidence mapping - but not high enough for auto-match
                return [
                    'reason' => "Medium-confidence team mapping found (not used for auto-match)",
                    'mapping_found' => true,
                    'confidence_score' => $mediumMapping->confidence_score,
                    'times_matched' => $mediumMapping->times_matched,
                    'primeplay_team' => $trackingTeamName,
                    'video_team' => $mediumMapping->video_team_name
                ];
            }
            
            // No existing mapping found - neutral score
            return [
                'reason' => 'No existing team mapping found',
                'mapping_found' => false
            ];
            
        } catch (\Exception $e) {
            // On error, return neutral
            return [
                'reason' => 'Error checking team mappings',
                'mapping_found' => false
            ];
        }
    }

    protected function getConfidenceLevel(float $score): string
    {
        if ($score >= 85) return 'high';
        if ($score >= 70) return 'medium';
        if ($score >= 55) return 'low';
        return 'very_low';
    }

    /**
     * Normalize timestamp to UTC DateTime object
     */
    private function normalizeToUTC(string $timestamp, string $timezone = 'UTC'): DateTime
    {
        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTime($timestamp, $tz);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt;
        } catch (\Exception $e) {
            // Fallback safely to current time in UTC when parsing fails
            return new DateTime('now', new DateTimeZone('UTC'));
        }
    }

    /**
     * Find best matches for a video from available tracking data
     */
    public function findBestMatchesForVideo(array $videoData, array $allTrackingData, int $limit = 5): array
    {
        $matches = [];
        
        foreach ($allTrackingData as $trackingData) {
            $result = $this->compareTrackingAndVideo($trackingData, $videoData);
            
            // Only include matches above minimum threshold (65)
            if ($result['score'] >= 65 && !isset($result['early_exit'])) {
                $matches[] = $result;
            }
        }
        
        // Sort by score descending
        usort($matches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Return top N matches
        return array_slice($matches, 0, $limit);
    }

    /**
     * Find best matches for tracking from available video data
     */
    public function findBestMatchesForTracking(array $trackingData, array $allVideoData, int $limit = 5): array
    {
        $matches = [];
        
        foreach ($allVideoData as $videoData) {
            $result = $this->compareTrackingAndVideo($trackingData, $videoData);
            
            // Only include matches above minimum threshold (65)
            if ($result['score'] >= 65 && !isset($result['early_exit'])) {
                $matches[] = $result;
            }
        }
        
        // Sort by score descending
        usort($matches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Return top N matches
        return array_slice($matches, 0, $limit);
    }
}
