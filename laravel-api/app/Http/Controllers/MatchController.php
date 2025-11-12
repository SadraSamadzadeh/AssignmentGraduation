<?php

namespace App\Http\Controllers;

use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    private MatchingService $matchingService;

    public function __construct()
    {
        $this->matchingService = new MatchingService();
    }

    /**
     * Match tracking and video data
     */
    public function match(Request $request): JsonResponse
    {
        $trackingData = $request->input('trackingData');
        $videoData = $request->input('videoData');

        if (!$trackingData || !$videoData) {
            return response()->json([
                'error' => 'Please provide both trackingData and videoData'
            ], 400);
        }

        $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

        return response()->json([
            'success' => true,
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id'],
            'match_score' => $result['score'],
            'confidence_level' => $result['confidence'],
            'details' => $result['reasons']
        ]);
    }

    /**
     * Test with sample data
     */
    public function test(): JsonResponse
    {
        $trackingData = [
            'id' => 184,
            'name' => 'Training Capelle 1',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-10-16T17:30:13.300Z',
            'endTime' => '2025-10-16T23:59:59.800Z'
        ];

        $videoData = [
            'id' => 'e5652e87-3409-4907-90d8-e95343014452',
            'club' => ['name' => 'VV Capelle'],
            'home' => ['name' => 'VV Capelle'],
            'away' => ['name' => 'VV Capelle'],
            'starting_at' => ['date' => '2025-10-16T20:25:00+02:00'],
            'stopping_at' => ['date' => '2025-10-16T20:59:00+02:00'],
            'timezone' => 'Europe/Amsterdam'
        ];

        $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

        return response()->json([
            'success' => true,
            'message' => 'Test completed with sample data',
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id'],
            'match_score' => $result['score'],
            'confidence_level' => $result['confidence'],
            'details' => $result['reasons']
        ]);
    }

    /**
     * Health check
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'Laravel Matching API is running',
            'timestamp' => now()
        ]);
    }
}