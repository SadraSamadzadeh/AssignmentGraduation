<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use App\Services\RabbitMQService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessageIngestionController extends Controller
{
    private RabbitMQService $rabbitMQService;

    public function __construct()
    {
        $this->rabbitMQService = new RabbitMQService();
    }

    /**
     * Entry point for receiving messages from external applications via RabbitMQ
     * This endpoint starts consuming messages from the tracking_data_queue
     */
    public function startConsumer(): JsonResponse
    {
        if (!$this->rabbitMQService->isConnected()) {
            return response()->json([
                'success' => false,
                'message' => 'RabbitMQ service is not available'
            ], 503);
        }

        try {
            Log::info('Starting message consumer for tracking data');
            
            // Start consuming messages from tracking queue
            $this->rabbitMQService->consumeQueue('tracking_data_queue', function ($message) {
                $this->processTrackingMessage($message);
            });

            return response()->json([
                'success' => true,
                'message' => 'Message consumer started successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to start message consumer', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start consumer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process incoming tracking message
     */
    private function processTrackingMessage($amqpMessage): void
    {
        try {
            $messageBody = json_decode($amqpMessage->body, true);
            
            Log::info('Received tracking message from RabbitMQ', [
                'message_id' => $messageBody['id'] ?? 'unknown',
                'team_name' => $messageBody['teamName'] ?? 'unknown'
            ]);

            // Dispatch job to process the message asynchronously
            ProcessIncomingMessage::dispatch($messageBody, 'tracking');

            // Acknowledge the message
            $amqpMessage->ack();

        } catch (\Exception $e) {
            Log::error('Failed to process tracking message', [
                'error' => $e->getMessage()
            ]);
            
            // Reject and requeue the message
            $amqpMessage->nack(true);
        }
    }

    /**
     * HTTP endpoint to receive tracking data directly (alternative to RabbitMQ)
     */
    public function receiveTrackingData(Request $request): JsonResponse
    {
        try {
            $trackingData = $request->all();
            
            Log::info('Received tracking data via HTTP', [
                'tracking_id' => $trackingData['id'] ?? 'unknown',
                'team_name' => $trackingData['teamName'] ?? 'unknown'
            ]);

            // Process the tracking data
            ProcessIncomingMessage::dispatch($trackingData, 'tracking');

            return response()->json([
                'success' => true,
                'message' => 'Tracking data received and queued for processing',
                'tracking_id' => $trackingData['id'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to receive tracking data', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process tracking data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * HTTP endpoint to receive video data directly (alternative to RabbitMQ)
     */
    public function receiveVideoData(Request $request): JsonResponse
    {
        try {
            $videoData = $request->all();
            
            Log::info('Received video data via HTTP', [
                'video_id' => $videoData['id'] ?? 'unknown',
                'home_team' => $videoData['home']['name'] ?? 'unknown'
            ]);

            // Process the video data
            ProcessIncomingMessage::dispatch($videoData, 'video');

            return response()->json([
                'success' => true,
                'message' => 'Video data received and queued for processing',
                'video_id' => $videoData['id'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to receive video data', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process video data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get status of message ingestion system
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'rabbitmq_connected' => $this->rabbitMQService->isConnected(),
            'queues' => [
                'tracking_data_queue' => 'active',
                'video_data_queue' => 'active',
                'matching_process_queue' => 'active'
            ],
            'timestamp' => now()
        ]);
    }
}
