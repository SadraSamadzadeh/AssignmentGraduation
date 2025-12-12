<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Central dispatcher for RabbitMQ messages
 * Routes messages to appropriate handlers based on event type and routing key
 */
class DispatchRabbitMQMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 3;

    protected $messageData;
    protected $routingKey;

    public function __construct(array $messageData, string $routingKey = 'unknown')
    {
        $this->messageData = $messageData;
        $this->routingKey = $routingKey;
    }

    public function handle(): void
    {
        Log::channel('stack')->info('Message received at dispatcher', [
            'routing_key' => $this->routingKey,
            'event_type' => $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? 'unknown',
            'received_at' => now()->toDateTimeString()
        ]);

        // Route based on routing key first (preferred)
        if (str_contains($this->routingKey, 'tracking')) {
            $this->dispatchToTrackingHandler();
            return;
        }

        if (str_contains($this->routingKey, 'video')) {
            $this->dispatchToVideoHandler();
            return;
        }

        // Fallback: route based on event type
        $eventType = $this->messageData['eventType'] ?? $this->messageData['event_type'] ?? null;
        
        if (!$eventType) {
            Log::warning('Message has no routing key pattern or event type', [
                'routing_key' => $this->routingKey,
                'message' => $this->messageData
            ]);
            return;
        }

        // Event type-based routing (fallback)
        switch ($eventType) {
            case 'MatchImportCompleted':
            case 'match.import.completed':
                $this->dispatchToTrackingHandler();
                break;
                
            case 'LiveDataRecordingStopped':
            case 'live.completed':
            case 'recording.completed':
                $this->dispatchToVideoHandler();
                break;
                
            default:
                Log::warning('Unknown event type', [
                    'event_type' => $eventType,
                    'routing_key' => $this->routingKey
                ]);
        }
    }

    protected function dispatchToTrackingHandler(): void
    {
        Log::info('Dispatching to ProcessPrimeplayMessage', [
            'routing_key' => $this->routingKey
        ]);
        
        ProcessPrimeplayMessage::dispatch($this->messageData, $this->routingKey);
    }

    protected function dispatchToVideoHandler(): void
    {
        Log::info('Dispatching to ProcessVideoMessage', [
            'routing_key' => $this->routingKey
        ]);
        
        ProcessVideoMessage::dispatch($this->messageData, $this->routingKey);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to dispatch RabbitMQ message', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
