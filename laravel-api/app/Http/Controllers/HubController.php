<?php

namespace App\Http\Controllers;

use App\Services\MessageBrokerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HubController extends Controller
{
    private MessageBrokerService $broker;

    public function __construct(MessageBrokerService $broker)
    {
        $this->broker = $broker;
    }

    /**
     * Endpoint for tracking backend to send data
     */
    public function receiveTrackingData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => 'required|string',
            'tracking_data' => 'required|array',
            'tracking_data.id' => 'required',
            'tracking_data.startTime' => 'required',
            'tracking_data.endTime' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid tracking data format',
                'details' => $validator->errors()
            ], 400);
        }

        try {
            $result = $this->broker->handleTrackingData(
                $request->input('tracking_data'),
                $request->input('request_id')
            );

            return response()->json([
                'success' => true,
                'hub_response' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Hub failed to process tracking data', [
                'request_id' => $request->input('request_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Hub processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint for video backend to send data
     */
    public function receiveVideoData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_id' => 'required|string',
            'video_data' => 'required|array',
            'video_data.id' => 'required',
            'video_data.starting_at' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid video data format',
                'details' => $validator->errors()
            ], 400);
        }

        try {
            $result = $this->broker->handleVideoData(
                $request->input('video_data'),
                $request->input('request_id')
            );

            return response()->json([
                'success' => true,
                'hub_response' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Hub failed to process video data', [
                'request_id' => $request->input('request_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Hub processing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint for backends to communicate through the hub
     */
    public function routeMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_backend' => 'required|string|in:tracking,video',
            'to_backend' => 'required|string|in:tracking,video',
            'message_data' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid routing request',
                'details' => $validator->errors()
            ], 400);
        }

        try {
            $result = $this->broker->routeMessage(
                $request->input('from_backend'),
                $request->input('to_backend'),
                $request->input('message_data')
            );

            return response()->json([
                'success' => true,
                'routing_result' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Hub failed to route message', [
                'from' => $request->input('from_backend'),
                'to' => $request->input('to_backend'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Hub routing failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get hub statistics and status
     */
    public function getHubStatus(): JsonResponse
    {
        try {
            $stats = $this->broker->getHubStats();

            return response()->json([
                'success' => true,
                'hub_status' => 'operational',
                'statistics' => $stats,
                'message' => 'Message broker hub is running'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'hub_status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test the hub with sample data
     */
    public function testHub(): JsonResponse
    {
        $requestId = 'test_' . uniqid();
        
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

        try {
            // Simulate tracking backend sending data
            $trackingResult = $this->broker->handleTrackingData($trackingData, $requestId);
            
            // Simulate video backend sending data (this should trigger matching)
            $videoResult = $this->broker->handleVideoData($videoData, $requestId);

            return response()->json([
                'success' => true,
                'test_completed' => true,
                'request_id' => $requestId,
                'tracking_result' => $trackingResult,
                'video_result' => $videoResult,
                'message' => 'Hub test completed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'test_completed' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}