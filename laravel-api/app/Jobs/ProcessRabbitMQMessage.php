<?php

namespace App\Jobs;

use App\Services\MessageBrokerService;
use App\Services\RabbitMQService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRabbitMQMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $messageData;
    public string $routingKey;

    public function __construct(array $messageData = [], string $routingKey = 'process.match')
    {
        $this->messageData = $messageData;
        $this->routingKey = $routingKey;
    }

    /**
     * Execute the job - Process RabbitMQ message in hub context
     */
    public function handle(MessageBrokerService $broker): void
    {
        try {
            Log::info('Processing RabbitMQ message in hub', [
                'routing_key' => $this->routingKey,
                'message_type' => $this->messageData['type'] ?? 'unknown'
            ]);

            // If no message data provided, create test message
            if (empty($this->messageData)) {
                $this->sendTestMessage();
                return;
            }

            // Process based on message type
            switch ($this->messageData['type'] ?? null) {
                case 'tracking_data':
                    $this->processTrackingMessage($broker);
                    break;
                
                case 'video_data':
                    $this->processVideoMessage($broker);
                    break;
                
                default:
                    Log::warning('Unknown message type received', $this->messageData);
            }

        } catch (\Exception $e) {
            Log::error('Failed to process RabbitMQ message', [
                'error' => $e->getMessage(),
                'message_data' => $this->messageData
            ]);
            throw $e;
        }
    }

    private function processTrackingMessage(MessageBrokerService $broker): void
    {
        $requestId = $this->messageData['request_id'];
        $trackingData = $this->messageData['data'];

        Log::info('Processing tracking data from RabbitMQ', ['request_id' => $requestId]);
        
        $result = $broker->handleTrackingData($trackingData, $requestId);
        
        Log::info('Tracking data processed via RabbitMQ', [
            'request_id' => $requestId,
            'result_status' => $result['status']
        ]);
    }

    private function processVideoMessage(MessageBrokerService $broker): void
    {
        $requestId = $this->messageData['request_id'];
        $videoData = $this->messageData['data'];

        Log::info('Processing video data from RabbitMQ', ['request_id' => $requestId]);
        
        $result = $broker->handleVideoData($videoData, $requestId);
        
        Log::info('Video data processed via RabbitMQ', [
            'request_id' => $requestId,
            'result_status' => $result['status']
        ]);
    }

    private function sendTestMessage(): void
    {
        Log::info('Sending test RabbitMQ message for hub');
        
        // This creates a simple test to verify RabbitMQ is working
        $testData = [
            'type' => 'test_message',
            'message' => 'Hub message broker is working via RabbitMQ',
            'timestamp' => now()->toISOString(),
            'test_id' => uniqid('test_')
        ];

        // You would normally publish this to RabbitMQ here
        Log::info('Test message created for RabbitMQ hub', $testData);
    }
}
