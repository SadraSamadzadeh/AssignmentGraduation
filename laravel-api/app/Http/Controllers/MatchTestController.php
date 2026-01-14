<?php

namespace App\Http\Controllers;

use App\Services\MatchingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MatchTestController extends Controller
{
    protected MatchingService $matchingService;

    public function __construct(MatchingService $matchingService)
    {
        $this->matchingService = $matchingService;
    }

    /**
     * Compare two JSON objects (tracking and video data) to check if they belong to the same match
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function compareMatches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tracking_data' => 'required|array',
            'video_data' => 'required|array',
        ]);

        try {
            // Ensure tracking_data has required fields
            $trackingData = $this->normalizeTrackingData($validated['tracking_data']);
            
            // Ensure video_data has required fields
            $videoData = $this->normalizeVideoData($validated['video_data']);

            // Run the matching algorithm
            $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

            return response()->json([
                'success' => true,
                'match_result' => $result,
                'is_match' => $result['score'] >= 65, // Threshold for considering it a match
                'message' => $this->getMatchMessage($result['score'], $result['confidence']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to compare matches',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Test endpoint to see expected data format for tracking data
     * 
     * @return JsonResponse
     */
    public function trackingDataExample(): JsonResponse
    {
        return response()->json([
            'description' => 'Example tracking data format from Primeplay system',
            'example' => [
                'id' => 1572,
                'team_name' => 'Test Team',
                'startTime' => '2025-11-29T13:33:53.7Z',
                'endTime' => '2025-11-29T15:30:07.6Z',
            ],
            'required_fields' => [
                'id' => 'Unique identifier for tracking data',
                'team_name' => 'Name of the team',
                'startTime' => 'Start time in ISO 8601 format',
                'endTime' => 'End time in ISO 8601 format',
            ],
            'optional_fields' => [
                'name' => 'Dataset name',
                'typeName' => 'Type of match',
            ]
        ]);
    }

    /**
     * Test endpoint to see expected data format for video data
     * 
     * @return JsonResponse
     */
    public function videoDataExample(): JsonResponse
    {
        return response()->json([
            'description' => 'Example video data format',
            'example' => [
                'id' => 456,
                'home_club_name' => 'Home Team',
                'away_club_name' => 'Away Team',
                'starting_at' => [
                    'date' => '2025-11-29T13:30:00Z'
                ],
                'stopping_at' => [
                    'date' => '2025-11-29T15:35:00Z'
                ],
                'timezone' => 'UTC'
            ],
            'required_fields' => [
                'id' => 'Unique identifier for video data',
                'starting_at.date' => 'Start time in ISO 8601 format',
                'stopping_at.date' => 'End time in ISO 8601 format',
            ],
            'optional_fields' => [
                'home_club_name' => 'Home team name',
                'away_club_name' => 'Away team name',
                'timezone' => 'Timezone of the video (defaults to UTC)',
            ]
        ]);
    }

    /**
     * Normalize tracking data to ensure required fields exist
     */
    private function normalizeTrackingData(array $data): array
    {
        // Ensure id exists
        if (!isset($data['id'])) {
            $data['id'] = rand(1000, 9999);
        }

        // Ensure team_name exists
        if (!isset($data['team_name'])) {
            $data['team_name'] = 'Unknown Team';
        }

        // Ensure startTime exists
        if (!isset($data['startTime'])) {
            throw new \Exception('tracking_data.startTime is required');
        }

        // Ensure endTime exists
        if (!isset($data['endTime'])) {
            throw new \Exception('tracking_data.endTime is required');
        }

        return $data;
    }

    /**
     * Normalize video data to ensure required fields exist
     */
    private function normalizeVideoData(array $data): array
    {
        // Ensure id exists
        if (!isset($data['id'])) {
            $data['id'] = rand(1000, 9999);
        }

        // Ensure starting_at exists
        if (!isset($data['starting_at']['date'])) {
            throw new \Exception('video_data.starting_at.date is required');
        }

        // Ensure stopping_at exists
        if (!isset($data['stopping_at']['date'])) {
            throw new \Exception('video_data.stopping_at.date is required');
        }

        // Ensure timezone exists (default to UTC)
        if (!isset($data['timezone'])) {
            $data['timezone'] = 'UTC';
        }

        return $data;
    }

    /**
     * Get a human-readable message based on the match score
     */
    private function getMatchMessage(float $score, string $confidence): string
    {
        if ($score >= 85) {
            return "Strong match! These records very likely belong to the same match.";
        } elseif ($score >= 70) {
            return "Good match. These records probably belong to the same match.";
        } elseif ($score >= 55) {
            return "Weak match. These records might belong to the same match, but manual verification recommended.";
        } else {
            return "Poor match. These records likely belong to different matches.";
        }
    }
}
