<?php

namespace App\Jobs;

use App\Models\TrackingDashboard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessPrimeplayMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
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
        echo "\n";
        echo "MESSAGE RECEIVED FROM PRIMEPLAY-BACKEND\n";
        echo "Routing Key: {$this->routingKey}\n";
        echo "Received at: " . now()->toDateTimeString() . "\n";
        echo "Message Content:\n";
        echo json_encode($this->messageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo "\n";

        Log::channel('stack')->info('Primeplay message received', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'received_at' => now()->toDateTimeString()
        ]);

        if (isset($this->messageData['EventType'])) {
            echo "Event Type: {$this->messageData['EventType']}\n";
            
            switch ($this->messageData['EventType']) {
                case 'MatchImportCompleted':
                    $this->handleMatchImportCompleted();
                    break;
                    
                case 'LiveDataRecordingStopped':
                    $this->handleLiveDataRecordingStopped();
                    break;
                    
                default:
                    $this->storeGenericMessage();
            }
        } else {
            $this->storeGenericMessage();
        }
        
        echo "Message processing completed\n\n";
    }

    protected function handleMatchImportCompleted(): void
    {
        $datasetId = $this->messageData['DatasetId'] ?? null;
        
        try {
            $tracking = TrackingDashboard::create([
                'dataset_id' => $datasetId,
                'event_type' => 'MatchImportCompleted',
                'event_data' => $this->messageData,
                'routing_key' => $this->routingKey,
                'received_at' => now(),
                'processed' => false
            ]);
            
            if ($datasetId) {
                $cacheKey = "primeplay:match:{$datasetId}";
                Cache::put($cacheKey, $this->messageData, now()->addHours(24));
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to store Primeplay message in database', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
        }
    }

    protected function handleLiveDataRecordingStopped(): void
    {
        $sessionId = $this->messageData['SessionId'] ?? null;
        
        try {
            $tracking = TrackingDashboard::create([
                'dataset_id' => $sessionId,
                'event_type' => 'LiveDataRecordingStopped',
                'event_data' => $this->messageData,
                'routing_key' => $this->routingKey,
                'received_at' => now(),
                'processed' => false
            ]);
            
            if ($sessionId) {
                $cacheKey = "primeplay:session:{$sessionId}";
                Cache::put($cacheKey, $this->messageData, now()->addHours(24));
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to store Primeplay message in database', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
        }
    }

    protected function storeGenericMessage(): void
    {
        try {
            TrackingDashboard::create([
                'dataset_id' => $this->messageData['DatasetId'] ?? $this->messageData['SessionId'] ?? null,
                'event_type' => $this->messageData['EventType'] ?? 'Unknown',
                'event_data' => $this->messageData,
                'routing_key' => $this->routingKey,
                'received_at' => now(),
                'processed' => false
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store Primeplay message in database', [
                'error' => $e->getMessage(),
                'message' => $this->messageData
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to process Primeplay message', [
            'routing_key' => $this->routingKey,
            'message' => $this->messageData,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
