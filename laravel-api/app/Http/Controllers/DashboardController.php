<?php

namespace App\Http\Controllers;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get all unmatched tracking data
     */
    public function getUnmatchedTracking(): JsonResponse
    {
        $trackingRecords = TrackingDashboard::whereNull('assigned_to_user_id')
            ->orderBy('received_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $trackingRecords->count(),
            'data' => $trackingRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'tracking_id' => $record->tracking_id,
                    'source_system' => $record->source_system,
                    'match_attempts' => $record->match_attempts,
                    'received_at' => $record->received_at,
                    'tracking_data' => json_decode($record->tracking_data, true)
                ];
            })
        ]);
    }

    /**
     * Get all unmatched video data
     */
    public function getUnmatchedVideo(): JsonResponse
    {
        $videoRecords = VideoDashboard::orderBy('received_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'count' => $videoRecords->count(),
            'data' => $videoRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'video_id' => $record->video_id,
                    'video_reference' => $record->video_reference,
                    'source_system' => $record->source_system,
                    'match_attempts' => $record->match_attempts,
                    'received_at' => $record->received_at,
                    'video_data' => json_decode($record->video_data, true)
                ];
            })
        ]);
    }

    /**
     * Get cached tracking data by ID
     */
    public function getCachedTracking(int $trackingId): JsonResponse
    {
        $cacheKey = "tracking_data_{$trackingId}";
        $data = Cache::get($cacheKey);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking data not found in cache'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'cache_key' => $cacheKey,
            'data' => $data
        ]);
    }

    /**
     * Get cached video data by ID
     */
    public function getCachedVideo(string $videoId): JsonResponse
    {
        $cacheKey = "video_data_{$videoId}";
        $data = Cache::get($cacheKey);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Video data not found in cache'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'cache_key' => $cacheKey,
            'data' => $data
        ]);
    }

    /**
     * Get all unmatched IDs from cache
     */
    public function getUnmatchedCache(): JsonResponse
    {
        $unmatchedTracking = Cache::get('unmatched_tracking_ids', []);
        $unmatchedVideo = Cache::get('unmatched_video_ids', []);

        return response()->json([
            'success' => true,
            'unmatched_tracking' => [
                'count' => count($unmatchedTracking),
                'ids' => $unmatchedTracking
            ],
            'unmatched_video' => [
                'count' => count($unmatchedVideo),
                'ids' => $unmatchedVideo
            ]
        ]);
    }

    /**
     * Dashboard statistics
     */
    public function getDashboardStats(): JsonResponse
    {
        $trackingCount = TrackingDashboard::count();
        $videoCount = VideoDashboard::count();
        $unmatchedTracking = Cache::get('unmatched_tracking_ids', []);
        $unmatchedVideo = Cache::get('unmatched_video_ids', []);

        return response()->json([
            'success' => true,
            'statistics' => [
                'tracking' => [
                    'total_in_database' => $trackingCount,
                    'cached_unmatched' => count($unmatchedTracking)
                ],
                'video' => [
                    'total_in_database' => $videoCount,
                    'cached_unmatched' => count($unmatchedVideo)
                ]
            ],
            'timestamp' => now()
        ]);
    }
}
