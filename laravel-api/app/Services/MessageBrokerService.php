<?php

namespace App\Services;

use App\Jobs\ProcessMatchingRequest;
use App\Models\GlobalMatches;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MessageBrokerService
{
    private RabbitMQService $rabbitmq;
    private MatchingService $matchingService;

    public function __construct(RabbitMQService $rabbitmq, MatchingService $matchingService)
    {
        $this->rabbitmq = $rabbitmq;
        $this->matchingService = $matchingService;
    }

    /**
     * Handle incoming data from tracking backend
     */
    public function handleTrackingData(array $trackingData, string $requestId): array
    {
        Log::info('Hub received tracking data', ['request_id' => $requestId, 'tracking_id' => $trackingData['id'] ?? 'unknown']);

        // Store tracking data temporarily in cache for matching
        Cache::put("tracking_data_{$requestId}", $trackingData, 300); // 5 minutes TTL

        // Check if we have corresponding video data
        $videoData = Cache::get("video_data_{$requestId}");
        
        if ($videoData) {
            // We have both! Process the match
            return $this->processMatch($trackingData, $videoData, $requestId);
        }

        // Send message to RabbitMQ to queue for processing
        $this->rabbitmq->sendTrackingData($trackingData, $requestId);

        return [
            'status' => 'queued',
            'message' => 'Tracking data received and queued for matching',
            'request_id' => $requestId,
            'waiting_for' => 'video_data'
        ];
    }

    /**
     * Handle incoming data from video backend
     */
    public function handleVideoData(array $videoData, string $requestId): array
    {
        Log::info('Hub received video data', ['request_id' => $requestId, 'video_id' => $videoData['id'] ?? 'unknown']);

        // Store video data temporarily in cache for matching
        Cache::put("video_data_{$requestId}", $videoData, 300); // 5 minutes TTL

        // Check if we have corresponding tracking data
        $trackingData = Cache::get("tracking_data_{$requestId}");
        
        if ($trackingData) {
            // We have both! Process the match
            return $this->processMatch($trackingData, $videoData, $requestId);
        }

        // Send message to RabbitMQ to queue for processing
        $this->rabbitmq->sendVideoData($videoData, $requestId);

        return [
            'status' => 'queued',
            'message' => 'Video data received and queued for matching',
            'request_id' => $requestId,
            'waiting_for' => 'tracking_data'
        ];
    }

    /**
     * Process matching when both data types are available
     */
    private function processMatch(array $trackingData, array $videoData, string $requestId): array
    {
        try {
            Log::info('Processing match in hub', ['request_id' => $requestId]);

            // Perform the matching
            $matchResult = $this->matchingService->compareTrackingAndVideo($trackingData, $videoData);

            $response = [
                'request_id' => $requestId,
                'tracking_id' => $trackingData['id'],
                'video_id' => $videoData['id'],
                'match_score' => $matchResult['score'],
                'confidence_level' => $matchResult['confidence'],
                'details' => $matchResult['reasons'],
                'processed_at' => now()->toISOString(),
                'hub_decision' => $matchResult['score'] >= 60 ? 'MATCH_APPROVED' : 'MATCH_REJECTED',
                'global_match_id' => $matchResult['score'] >= 60 ? 'match_' . uniqid() : null
            ];

            // Send responses back to both backends via RabbitMQ
            $this->rabbitmq->sendTrackingResponse($response, $requestId);
            $this->rabbitmq->sendVideoResponse($response, $requestId);

            // Clean up cache
            Cache::forget("tracking_data_{$requestId}");
            Cache::forget("video_data_{$requestId}");

            // Store successful matches
            if ($matchResult['score'] >= 60) {
                $this->storeMatch($response);
            }

            Log::info('Match processed successfully by hub', [
                'request_id' => $requestId,
                'score' => $matchResult['score'],
                'decision' => $response['hub_decision']
            ]);

            return [
                'status' => 'processed',
                'message' => 'Match completed and responses sent to backends',
                'match_result' => $response
            ];

        } catch (\Exception $e) {
            Log::error('Hub matching failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);

            $errorResponse = [
                'request_id' => $requestId,
                'error' => 'Hub processing failed',
                'message' => $e->getMessage(),
                'processed_at' => now()->toISOString()
            ];

            // Send error responses to both backends
            $this->rabbitmq->sendTrackingResponse($errorResponse, $requestId);
            $this->rabbitmq->sendVideoResponse($errorResponse, $requestId);

            throw $e;
        }
    }

    /**
     * Store successful matches in the hub
     */
    private function storeMatch(array $matchData): void
    {
        try {
            GlobalMatches::create([
                'global_match_id' => $matchData['global_match_id'],
                'tracking_id' => $matchData['tracking_id'],
                'video_id' => $matchData['video_id'],
                'match_score' => $matchData['match_score'],
                'confidence_level' => $matchData['confidence_level'],
                'match_details' => $matchData['details'],
                'tracking_data' => Cache::get("tracking_data_{$matchData['request_id']}", []),
                'video_data' => Cache::get("video_data_{$matchData['request_id']}", []),
                'processed_by' => 'message_broker_hub',
                'matched_at' => now(),
            ]);

            Log::info('Match stored in database', [
                'global_match_id' => $matchData['global_match_id'],
                'score' => $matchData['match_score']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store match in database', [
                'global_match_id' => $matchData['global_match_id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get hub statistics
     */
    public function getHubStats(): array
    {
        $totalMatches = GlobalMatches::count();
        $successfulMatches = GlobalMatches::successful()->count();
        $recentMatches = GlobalMatches::recent(1)->count(); // Last 24 hours
        $averageScore = GlobalMatches::avg('match_score') ?? 0;

        return [
            'total_matches_processed' => $totalMatches,
            'successful_matches' => $successfulMatches,
            'success_rate' => $totalMatches > 0 ? round(($successfulMatches / $totalMatches) * 100, 2) : 0,
            'recent_matches_24h' => $recentMatches,
            'average_match_score' => round($averageScore, 2),
            'pending_tracking_requests' => Cache::get('hub_pending_tracking', 0),
            'pending_video_requests' => Cache::get('hub_pending_video', 0),
            'hub_uptime' => now()->toISOString(),
            'database_status' => 'connected'
        ];
    }

    /**
     * Handle cross-communication between backends
     */
    public function routeMessage(string $fromBackend, string $toBackend, array $messageData): array
    {
        $requestId = 'route_' . uniqid();
        
        Log::info('Hub routing message between backends', [
            'from' => $fromBackend,
            'to' => $toBackend,
            'request_id' => $requestId
        ]);

        $routedMessage = [
            'request_id' => $requestId,
            'from_backend' => $fromBackend,
            'message_data' => $messageData,
            'routed_at' => now()->toISOString(),
            'routed_by_hub' => true
        ];

        // Route to appropriate backend
        if ($toBackend === 'tracking') {
            $this->rabbitmq->sendTrackingResponse($routedMessage, $requestId);
        } elseif ($toBackend === 'video') {
            $this->rabbitmq->sendVideoResponse($routedMessage, $requestId);
        }

        return [
            'status' => 'routed',
            'message' => "Message routed from {$fromBackend} to {$toBackend}",
            'request_id' => $requestId
        ];
    }
}