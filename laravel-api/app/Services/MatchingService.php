<?php

namespace App\Services;

use DateTime;
use DateTimeZone;

class MatchingService
{
    public function compareTrackingAndVideo(array $trackingData, array $videoData): array
    {
        $score = 0;
        $reasons = [];

        // Date/Time proximity scoring
        $timeScore = $this->calculateTimeProximity($trackingData, $videoData);
        $score += $timeScore * 0.4; // 40% weight
        $reasons[] = "Time proximity: {$timeScore}";

        // Team name similarity scoring
        $nameScore = $this->calculateNameSimilarity($trackingData, $videoData);
        $score += $nameScore * 0.3; // 30% weight
        $reasons[] = "Name similarity: {$nameScore}";

        // Duration similarity scoring
        $durationScore = $this->calculateDurationSimilarity($trackingData, $videoData);
        $score += $durationScore * 0.2; // 20% weight
        $reasons[] = "Duration similarity: {$durationScore}";

        // Temporal overlap scoring
        $overlapScore = $this->calculateTemporalOverlap($trackingData, $videoData);
        $score += $overlapScore * 0.1; // 10% weight
        $reasons[] = "Temporal overlap: {$overlapScore}";

        return [
            'score' => round($score, 2),
            'confidence' => $this->getConfidenceLevel($score),
            'reasons' => $reasons,
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id']
        ];
    }

    private function calculateTimeProximity(array $trackingData, array $videoData): float
    {
        $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
        $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
        
        $timeDiffMinutes = abs($trackingStart->getTimestamp() - $videoStart->getTimestamp()) / 60;
        
        if ($timeDiffMinutes <= 30) return 100;
        if ($timeDiffMinutes <= 60) return 80;
        if ($timeDiffMinutes <= 120) return 60;
        if ($timeDiffMinutes <= 240) return 40;
        return 20;
    }

    private function calculateNameSimilarity(array $trackingData, array $videoData): float
    {
        $trackingTeam = $this->normalizeTeamName($trackingData['teamName'] ?? '');
        
        $homeTeam = $this->normalizeTeamName($videoData['home']['name'] ?? '');
        $awayTeam = $this->normalizeTeamName($videoData['away']['name'] ?? '');
        $clubName = $this->normalizeTeamName($videoData['club']['name'] ?? '');
        
        $homeScore = $this->calculateLevenshteinSimilarity($trackingTeam, $homeTeam);
        $awayScore = $this->calculateLevenshteinSimilarity($trackingTeam, $awayTeam);
        $clubScore = $this->calculateLevenshteinSimilarity($trackingTeam, $clubName);
        
        return max($homeScore, $awayScore, $clubScore);
    }

    private function normalizeTeamName(string $teamName): string
    {
        $teamName = strtolower(trim($teamName));
        
        // Remove common prefixes/suffixes
        $patterns = [
            '/^(fc|sc|vv|sv|afc|bv|cv|dv|gv|hv|jv|kv|lv|mv|nv|ov|pv|qv|rv|tv|uv|wv|xv|yv|zv)\s+/',
            '/\s+(fc|sc|vv|sv|1|2|3|4|5|6|7|8|9)$/',
            '/\s+(team|squad|club|united|city|town|rovers|wanderers|athletic)$/'
        ];
        
        foreach ($patterns as $pattern) {
            $teamName = preg_replace($pattern, '', $teamName);
        }
        
        return trim($teamName);
    }

    private function calculateLevenshteinSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) return 0;
        
        $distance = levenshtein($str1, $str2);
        $maxLength = max(strlen($str1), strlen($str2));
        
        if ($maxLength === 0) return 100;
        
        return (1 - ($distance / $maxLength)) * 100;
    }

    private function calculateDurationSimilarity(array $trackingData, array $videoData): float
    {
        $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
        $trackingEnd = $this->normalizeToUTC($trackingData['endTime']);
        $trackingDuration = $trackingEnd->getTimestamp() - $trackingStart->getTimestamp();
        
        $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
        $videoEnd = $this->normalizeToUTC($videoData['stopping_at']['date'], $videoData['timezone'] ?? 'UTC');
        $videoDuration = $videoEnd->getTimestamp() - $videoStart->getTimestamp();
        
        if ($trackingDuration <= 0 || $videoDuration <= 0) return 0;
        
        $ratio = min($trackingDuration, $videoDuration) / max($trackingDuration, $videoDuration);
        return $ratio * 100;
    }

    private function calculateTemporalOverlap(array $trackingData, array $videoData): float
    {
        $trackingStart = $this->normalizeToUTC($trackingData['startTime']);
        $trackingEnd = $this->normalizeToUTC($trackingData['endTime']);
        
        $videoStart = $this->normalizeToUTC($videoData['starting_at']['date'], $videoData['timezone'] ?? 'UTC');
        $videoEnd = $this->normalizeToUTC($videoData['stopping_at']['date'], $videoData['timezone'] ?? 'UTC');
        
        $overlapStart = max($trackingStart->getTimestamp(), $videoStart->getTimestamp());
        $overlapEnd = min($trackingEnd->getTimestamp(), $videoEnd->getTimestamp());
        
        if ($overlapStart >= $overlapEnd) return 0;
        
        $overlapDuration = $overlapEnd - $overlapStart;
        $totalDuration = max(
            $trackingEnd->getTimestamp() - $trackingStart->getTimestamp(),
            $videoEnd->getTimestamp() - $videoStart->getTimestamp()
        );
        
        return ($overlapDuration / $totalDuration) * 100;
    }

    private function normalizeToUTC(string $timestamp, string $timezone = 'UTC'): DateTime
    {
        try {
            $tz = new DateTimeZone($timezone);
            $date = new DateTime($timestamp, $tz);
            $date->setTimezone(new DateTimeZone('UTC'));
            return $date;
        } catch (\Exception $e) {
            return new DateTime($timestamp);
        }
    }

    private function getConfidenceLevel(float $score): string
    {
        if ($score >= 80) return 'confident';
        if ($score >= 60) return 'likely';
        if ($score >= 40) return 'possible';
        return 'unlikely';
    }

    public function processBatchMatching(array $trackingObjects, array $videoObjects): array
    {
        $matches = [];
        
        foreach ($trackingObjects as $tracking) {
            foreach ($videoObjects as $video) {
                $result = $this->compareTrackingAndVideo($tracking, $video);
                
                // Only store matches with score >= 60
                if ($result['score'] >= 60) {
                    $matches[] = [
                        'global_id' => uniqid('match_'),
                        'tracking_data' => $tracking,
                        'video_data' => $video,
                        'match_result' => $result
                    ];
                }
            }
        }
        
        return $matches;
    }
}