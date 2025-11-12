<?php

namespace App\Http\Controllers;

use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class SimpleMatchController extends Controller
{
    private MatchingService $matchingService;

    public function __construct()
    {
        $this->matchingService = new MatchingService();
    }

    /**
     * Simple matching endpoint - exactly like TypeScript version
     */
    public function performMatch(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'trackingData' => 'required|array',
                'trackingData.id' => 'required',
                'trackingData.teamName' => 'required|string',
                'trackingData.startTime' => 'required|date',
                'trackingData.endTime' => 'required|date',
                'videoData' => 'required|array',
                'videoData.id' => 'required',
                'videoData.starting_at' => 'required|array',
                'videoData.starting_at.date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $trackingData = $request->input('trackingData');
            $videoData = $request->input('videoData');

            // Perform matching (same algorithm as TypeScript)
            $matchResult = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

            $result = [
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'reasons' => $matchResult['reasons'],
                'stored' => false,
                'global_match_id' => null,
                'should_store' => $matchResult['score'] >= 60
            ];

            // Generate global match ID if score >= 60 (same threshold as TypeScript)
            if ($matchResult['score'] >= 60) {
                $result['global_match_id'] = 'match_' . uniqid();
                $result['message'] = 'Match score high enough for storage (≥60)';
            } else {
                $result['message'] = 'Match score too low for storage (<60)';
            }

            return response()->json([
                'success' => true,
                'message' => 'Matching completed successfully',
                'data' => $result
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Matching failed: ' . $e->getMessage(),
                'error' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Test endpoint with sample data
     */
    public function testMatch(): JsonResponse
    {
        $trackingData = [
            'id' => 184,
            'name' => 'Training Capelle 1',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-10-16T17:30:13.300Z',
            'endTime' => '2025-10-16T23:59:59.800Z',
            'avgTotalTimeActive' => '01:06:08.5472727',
            'activities' => [
                [
                    'type' => 'running',
                    'duration' => '00:30:00',
                    'intensity' => 'high'
                ]
            ]
        ];

        $videoData = [
            'id' => 'e5652e87-3409-4907-90d8-e95343014452',
            'club' => [
                'id' => 'club-123',
                'name' => 'VV Capelle'
            ],
            'home' => ['name' => 'VV Capelle'],
            'away' => ['name' => 'VV Capelle'],
            'starting_at' => ['date' => '2025-10-16T20:25:00+02:00'],
            'stopping_at' => ['date' => '2025-10-16T20:59:00+02:00'],
            'timezone' => 'Europe/Amsterdam'
        ];

        // Create fake request with test data
        $testRequest = new Request([
            'trackingData' => $trackingData,
            'videoData' => $videoData
        ]);

        return $this->performMatch($testRequest);
    }
}