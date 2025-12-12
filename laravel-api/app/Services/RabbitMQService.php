<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    private $connection;
    private $channel;
    private $isConnected = false;

    public function __construct()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', '127.0.0.1'),
                env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASSWORD', 'guest'),
                env('RABBITMQ_VHOST', '/')
            );
            $this->channel = $this->connection->channel();
            
            $this->setupQueues();
            
            $this->isConnected = true;
            Log::info('RabbitMQ connection established successfully');
        } catch (\Exception $e) {
            Log::warning('RabbitMQ connection failed - service will operate in degraded mode', [
                'error' => $e->getMessage(),
                'host' => env('RABBITMQ_HOST', '127.0.0.1')
            ]);
            $this->isConnected = false;
        }
    }
    
    /**
     * Check if RabbitMQ is connected
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    /**
     * Setup RabbitMQ queues and exchanges for the hub
     */
    private function setupQueues(): void
    {
        $this->channel->exchange_declare('matching_hub', 'direct', false, true, false);
        
        // Incoming queues from backends
        $this->channel->queue_declare('tracking_data_queue', false, true, false, false);
        $this->channel->queue_declare('video_data_queue', false, true, false, false);
        
        // $this->channel->queue_declare('tracking_response_queue', false, true, false, false);
        // $this->channel->queue_declare('video_response_queue', false, true, false, false);
        
        $this->channel->queue_bind('tracking_data_queue', 'matching_hub', 'tracking.data');
        $this->channel->queue_bind('video_data_queue', 'matching_hub', 'video.data');
        // $this->channel->queue_bind('tracking_response_queue', 'matching_hub', 'tracking.response');
        // $this->channel->queue_bind('video_response_queue', 'matching_hub', 'video.response');
    }

    /**
     * Publish message to specific queue/routing key
     */
    public function publishMessage(string $routingKey, array $data, array $headers = []): void
    {
        if (!$this->isConnected) {
            Log::warning('Cannot publish message - RabbitMQ not connected', [
                'routing_key' => $routingKey
            ]);
            return;
        }
        
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
        if (!$this->isConnected) {
            Log::warning('Cannot consume queue - RabbitMQ not connected', [
                'queue' => $queueName
            ]);
            return;
        }
        
        $this->channel->basic_consume(
            $queueName,
            '',
            false,
            false,
            false,
            false,
            $callback
        );

        Log::info('Starting to consume messages', ['queue' => $queueName]);
        
        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }
    // /**
    //  * Send response back to tracking backend
    //  */
    // public function sendTrackingResponse(array $responseData, string $requestId): void
    // {
    //     $message = [
    //         'request_id' => $requestId,
    //         'response_data' => $responseData,
    //         'timestamp' => now()->toISOString(),
    //         'hub_processed' => true
    //     ];

    //     $this->publishMessage('tracking.response', $message);
    // }

    // /**
    //  * Send response back to video backend
    //  */
    // public function sendVideoResponse(array $responseData, string $requestId): void
    // {
    //     $message = [
    //         'request_id' => $requestId,
    //         'response_data' => $responseData,
    //         'timestamp' => now()->toISOString(),
    //         'hub_processed' => true
    //     ];

    //     $this->publishMessage('video.response', $message);
    // }

    /**
     * Close connection
     */
    public function close(): void
    {
        if ($this->isConnected) {
            $this->channel->close();
            $this->connection->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}