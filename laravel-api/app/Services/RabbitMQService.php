<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    private $connection;
    private $channel;

    public function __construct()
    {
        $this->connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', '127.0.0.1'),
            env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            env('RABBITMQ_VHOST', '/')
        );
        $this->channel = $this->connection->channel();
        
        // Declare exchanges and queues
        $this->setupQueues();
    }

    /**
     * Setup RabbitMQ queues and exchanges for the hub
     */
    private function setupQueues(): void
    {
        // Declare exchange for routing messages
        $this->channel->exchange_declare('matching_hub', 'direct', false, true, false);
        
        // Incoming queues from backends
        $this->channel->queue_declare('tracking_data_queue', false, true, false, false);
        $this->channel->queue_declare('video_data_queue', false, true, false, false);
        
        // Response queues to backends
        $this->channel->queue_declare('tracking_response_queue', false, true, false, false);
        $this->channel->queue_declare('video_response_queue', false, true, false, false);
        
        // Internal processing queue
        $this->channel->queue_declare('matching_process_queue', false, true, false, false);
        
        // Bind queues to exchange
        $this->channel->queue_bind('tracking_data_queue', 'matching_hub', 'tracking.data');
        $this->channel->queue_bind('video_data_queue', 'matching_hub', 'video.data');
        $this->channel->queue_bind('tracking_response_queue', 'matching_hub', 'tracking.response');
        $this->channel->queue_bind('video_response_queue', 'matching_hub', 'video.response');
        $this->channel->queue_bind('matching_process_queue', 'matching_hub', 'process.match');
    }

    /**
     * Publish message to specific queue/routing key
     */
    public function publishMessage(string $routingKey, array $data, array $headers = []): void
    {
        $message = new AMQPMessage(
            json_encode($data),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $headers
            ]
        );

        $this->channel->basic_publish($message, 'matching_hub', $routingKey);
        
        Log::info('Message published to RabbitMQ', [
            'routing_key' => $routingKey,
            'data_size' => strlen(json_encode($data))
        ]);
    }

    /**
     * Consume messages from a specific queue
     */
    public function consumeQueue(string $queueName, callable $callback): void
    {
        $this->channel->basic_consume(
            $queueName,
            '',
            false,
            false, // no_ack = false (manual acknowledgment)
            false,
            false,
            $callback
        );

        Log::info('Starting to consume messages', ['queue' => $queueName]);
        
        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * Send tracking data to processing queue
     */
    public function sendTrackingData(array $trackingData, string $requestId): void
    {
        $message = [
            'type' => 'tracking_data',
            'request_id' => $requestId,
            'data' => $trackingData,
            'timestamp' => now()->toISOString(),
            'source' => 'tracking_backend'
        ];

        $this->publishMessage('process.match', $message);
    }

    /**
     * Send video data to processing queue
     */
    public function sendVideoData(array $videoData, string $requestId): void
    {
        $message = [
            'type' => 'video_data',
            'request_id' => $requestId,
            'data' => $videoData,
            'timestamp' => now()->toISOString(),
            'source' => 'video_backend'
        ];

        $this->publishMessage('process.match', $message);
    }

    /**
     * Send response back to tracking backend
     */
    public function sendTrackingResponse(array $responseData, string $requestId): void
    {
        $message = [
            'request_id' => $requestId,
            'response_data' => $responseData,
            'timestamp' => now()->toISOString(),
            'hub_processed' => true
        ];

        $this->publishMessage('tracking.response', $message);
    }

    /**
     * Send response back to video backend
     */
    public function sendVideoResponse(array $responseData, string $requestId): void
    {
        $message = [
            'request_id' => $requestId,
            'response_data' => $responseData,
            'timestamp' => now()->toISOString(),
            'hub_processed' => true
        ];

        $this->publishMessage('video.response', $message);
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        $this->channel->close();
        $this->connection->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}