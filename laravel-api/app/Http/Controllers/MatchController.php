<?php

namespace App\Http\Controllers;

use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class MatchController extends Controller
{
    private MatchingService $matchingService;

    public function __construct()
    {
        $this->matchingService = new MatchingService();
    }

    /**
     * Main matching endpoint - give it tracking and video data, get match score
     */
    public function match(Request $request): JsonResponse
    {
        try {
            $trackingData = $request->input('trackingData');
            $videoData = $request->input('videoData');

            if (!$trackingData || !$videoData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide both trackingData and videoData'
                ], 400);
            }

            // Perform matching using the same algorithm as TypeScript
            $result = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

            // Generate global match ID if score is good (≥60)
            $response = [
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'match_score' => $result['score'],
                'confidence_level' => $result['confidence'],
                'reasons' => $result['reasons'],
                'should_store' => $result['score'] >= 60,
                'global_match_id' => $result['score'] >= 60 ? 'match_' . uniqid() : null
            ];

            return response()->json([
                'success' => true,
                'data' => $response
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Matching failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint with sample data
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

        $response = [
            'tracking_id' => $trackingData['id'],
            'video_id' => $videoData['id'],
            'match_score' => $result['score'],
            'confidence_level' => $result['confidence'],
            'reasons' => $result['reasons'],
            'should_store' => $result['score'] >= 60,
            'global_match_id' => $result['score'] >= 60 ? 'match_' . uniqid() : null
        ];

        return response()->json([
            'success' => true,
            'message' => 'Test completed with sample data',
            'data' => $response
        ]);
    }

    /**
     * Health check
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Laravel Matching API is running',
            'status' => 'healthy',
            'timestamp' => now()
        ]);
    }
}