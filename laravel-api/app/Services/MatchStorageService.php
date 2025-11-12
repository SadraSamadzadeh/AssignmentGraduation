<?php

namespace App\Services;

use App\Models\TrackingMatch;
use App\Models\VideoMatch;
use App\Models\GlobalMatch;
use App\Models\TrackingActivity;
use Illuminate\Support\Facades\DB;

class MatchStorageService
{
    public function storeMatch(array $trackingData, array $videoData, array $matchResult): array
    {
        return DB::transaction(function () use ($trackingData, $videoData, $matchResult) {
            // Store tracking match
            $trackingMatch = TrackingMatch::create([
                'tracking_id' => $trackingData['id'],
                'name' => $trackingData['name'] ?? '',
                'team_name' => $trackingData['teamName'] ?? '',
                'start_time' => $trackingData['startTime'],
                'end_time' => $trackingData['endTime'],
                'avg_total_time_active' => $trackingData['avgTotalTimeActive'] ?? null
            ]);

            // Store tracking activities
            if (isset($trackingData['activities']) && is_array($trackingData['activities'])) {
                foreach ($trackingData['activities'] as $activity) {
                    TrackingActivity::create([
                        'tracking_match_id' => $trackingMatch->id,
                        'activity_type' => $activity['type'] ?? 'unknown',
                        'duration' => $activity['duration'] ?? null,
                        'intensity' => $activity['intensity'] ?? null,
                        'details' => $activity
                    ]);
                }
            }

            // Store video match
            $videoMatch = VideoMatch::create([
                'video_id' => $videoData['id'],
                'club_id' => $videoData['club']['id'] ?? null,
                'club_name' => $videoData['club']['name'] ?? '',
                'home_team' => $videoData['home']['name'] ?? '',
                'away_team' => $videoData['away']['name'] ?? '',
                'start_time' => $videoData['starting_at']['date'],
                'end_time' => $videoData['stopping_at']['date'] ?? null,
                'timezone' => $videoData['timezone'] ?? 'UTC'
            ]);

            // Store global match
            $globalMatch = GlobalMatch::create([
                'global_id' => uniqid('match_'),
                'tracking_match_id' => $trackingMatch->id,
                'video_match_id' => $videoMatch->id,
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'reasons' => $matchResult['reasons']
            ]);

            return [
                'global_id' => $globalMatch->global_id,
                'tracking_match' => $trackingMatch->load('activities'),
                'video_match' => $videoMatch,
                'global_match' => $globalMatch,
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence']
            ];
        });
    }

    public function storeBatchMatches(array $matches): array
    {
        $results = [];
        
        foreach ($matches as $match) {
            $result = $this->storeMatch(
                $match['tracking_data'],
                $match['video_data'],
                $match['match_result']
            );
            $results[] = $result;
        }
        
        return $results;
    }

    public function getAllMatches(int $limit = 50, int $offset = 0): array
    {
        $matches = GlobalMatch::with(['trackingMatch.activities', 'videoMatch'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return [
            'matches' => $matches->toArray(),
            'total' => GlobalMatch::count(),
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    public function getMatchById(string $globalId): ?array
    {
        $match = GlobalMatch::with(['trackingMatch.activities', 'videoMatch'])
            ->where('global_id', $globalId)
            ->first();

        return $match ? $match->toArray() : null;
    }

    public function getMatchStatistics(): array
    {
        $stats = GlobalMatch::select('confidence_level', DB::raw('COUNT(*) as count'))
            ->groupBy('confidence_level')
            ->get()
            ->keyBy('confidence_level');

        $avgScore = GlobalMatch::avg('match_score');

        return [
            'total_matches' => GlobalMatch::count(),
            'average_score' => round($avgScore, 2),
            'confidence_breakdown' => [
                'confident' => $stats['confident']->count ?? 0,
                'likely' => $stats['likely']->count ?? 0,
                'possible' => $stats['possible']->count ?? 0,
                'unlikely' => $stats['unlikely']->count ?? 0
            ],
            'last_updated' => now()
        ];
    }

    public function deleteMatch(string $globalId): bool
    {
        return DB::transaction(function () use ($globalId) {
            $globalMatch = GlobalMatch::where('global_id', $globalId)->first();
            
            if (!$globalMatch) {
                return false;
            }

            // Delete related activities
            if ($globalMatch->trackingMatch) {
                $globalMatch->trackingMatch->activities()->delete();
                $globalMatch->trackingMatch->delete();
            }

            // Delete video match
            if ($globalMatch->videoMatch) {
                $globalMatch->videoMatch->delete();
            }

            // Delete global match
            $globalMatch->delete();

            return true;
        });
    }
}