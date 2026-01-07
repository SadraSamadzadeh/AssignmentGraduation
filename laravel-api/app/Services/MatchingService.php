<?php

namespace App\Services;

use DateTime;
use DateTimeZone;

class MatchingService
{
    /**
     * Compare tracking and video data using TIME-FOCUSED matching approach
     * 
     * CRITICAL DATA STRUCTURE UNDERSTANDING:
     * 
     * Primeplay Tracking Data:
     * - teamName: Generic identifier like "Test Team", "Team 1" (NOT actual club names)
     * - name: Dataset name like "Match Test Team"
     * - NO home/away club information available
     * 
     * Video Data:
     * - home.name / away.name: Actual club names (e.g., "VV Capelle", "FC 's-Gravenzande")
     * - home.team.name / away.team.name: Generic identifiers (e.g., "1", "2")
     * - club.name: Primary club hosting the recording
     * 
     * MATCHING STRATEGY (Time-Focused):
     * Since tracking data lacks club names, we CANNOT reliably match on team names.
     * Instead, we rely heavily on temporal factors:
     * 
     * - Time Proximity: 70% weight - PRIMARY matching factor
     * - Duration Similarity: 20% weight - Confirms match quality
     * - Temporal Overlap: 10% weight - Final validation
     * 
     * Minimum score threshold: 65 points
     * Auto-confirm threshold: 85 points
     */
    public function compareTrackingAndVideo(array $trackingData, array $videoData): array
    {
        $score = 0;
        $reasons = [];
        $breakdown = [];

        // STAGE 1: Time Proximity (70% weight - PRIMARY INDICATOR)
        // This is the MOST RELIABLE matching factor since tracking lacks club names
        $timeScore = $this->calculateTimeProximity($trackingData, $videoData);
        $timeWeight = 70;
        $score += $timeScore * ($timeWeight / 100);
        $reasons[] = "Time proximity: {$timeScore}/100";
        $breakdown['time_proximity'] = [
            'score' => $timeScore,
            'weight' => $timeWeight,
            'weighted_score' => round($timeScore * ($timeWeight / 100), 2)
        ];

        // Early exit if time difference is too large (optimization)
        if ($timeScore < 40) {
            return [
                'score' => round($score, 2),
                'confidence' => 'unlikely',
                'reasons' => ['Time difference too large - likely different matches'],
                'breakdown' => $breakdown,
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'early_exit' => true
            ];
        }

        // STAGE 2: Duration Similarity (20% weight - CONFIRMATION)
        $durationScore = $this->calculateDurationSimilarity($trackingData, $videoData);
        $durationWeight = 20;
        $score += $durationScore * ($durationWeight / 100);
        $reasons[] = "Duration similarity: {$durationScore}/100";
        $breakdown['duration_similarity'] = [
            'score' => $durationScore,
            'weight' => $durationWeight,
            'weighted_score' => round($durationScore * ($durationWeight / 100), 2)
        ];

        // STAGE 3: Temporal Overlap (10% weight - FINAL VERIFICATION)
        $overlapScore = $this->calculateTemporalOverlap($trackingData, $videoData);
        $overlapWeight =  10;
        $score += $overlapScore * ($overlapWeight / 100);
        $reasons[] = "Temporal overlap: {$overlapScore}/100";
        $breakdown['temporal_overlap'] = [
            'score' => $overlapScore,
            'weight' => $overlapWeight,
            'weighted_score' => round($overlapScore * ($overlapWeight / 100), 2)
        ];

        return [
            'score' => round($score, 2),
            'confidence' => $this->getConfidenceLevel($score),
            'reasons' => $reasons,
            'breakdown' => $breakdown,
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id']
        ];
    }

    /**
     * Calculate time proximity based on actual session start times
     * Since timestamps represent actual start times, matches should be very close
     */
    private function calculateTimeProximity(array $trackingData, array $videoData): float
    {
        $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
        $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
        
        $timeDiffMinutes = abs($trackingStart->getTimestamp() - $videoStart->getTimestamp()) / 60;
        
        // Scoring thresholds: [max_minutes, score]
        $thresholds = [
            [5, 100],    // Within 5 minutes - perfect match
            [10, 95],    // Within 10 minutes - excellent
            [15, 90],    // Within 15 minutes - very good
            [30, 80],    // Within 30 minutes - good
            [45, 70],    // Within 45 minutes - acceptable
            [60, 60],    // Within 1 hour - marginal
            [90, 50],    // Within 90 minutes - unlikely same match
            [120, 40],   // Within 2 hours - probably different
            [180, 30],   // Within 3 hours - likely different
            [360, 20],   // Within 6 hours - very unlikely
            [720, 10],   // Within 12 hours - different matches
        ];
        
        foreach ($thresholds as [$minutes, $score]) {
            if ($timeDiffMinutes <= $minutes) {
                return $score;
            }
        }
        
        return 0; // More than 12 hours - no match
    }

    /**
     * Calculate duration similarity
     * Compares the length of tracking session vs video recording
     */
    private function calculateDurationSimilarity(array $trackingData, array $videoData): float
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
            
            $diffPercent = abs($trackingDuration - $videoDuration) / $maxDuration * 100;
            
            // Scoring thresholds: [max_percent_diff, score]
            $thresholds = [
                [5, 100],    // Within 5% - perfect
                [10, 90],    // Within 10% - excellent
                [20, 75],    // Within 20% - good
                [35, 55],    // Within 35% - acceptable
                [50, 35],    // Within 50% - poor
            ];
            
            foreach ($thresholds as [$percent, $score]) {
                if ($diffPercent <= $percent) {
                    return $score;
                }
            }
            
            return 15; // More than 50% difference
            
        } catch (\Exception $e) {
            return 50; // Neutral score on error
        }
    }

    /**
     * Calculate temporal overlap between tracking and video sessions
     * Measures how much the two time ranges actually overlap
     */
    private function calculateTemporalOverlap(array $trackingData, array $videoData): float
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
     * Determine confidence level based on final score
     */
    private function getConfidenceLevel(float $score): string
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
            return new DateTime($timestamp);
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
