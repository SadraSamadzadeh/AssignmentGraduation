<?php

namespace App\Jobs;

use App\Services\MatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMatchingRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $trackingData;
    public array $videoData;
    public string $requestId;
    public ?string $replyTo; // For response routing

    /**
     * Create a new job instance.
     */
    public function __construct(array $trackingData, array $videoData, string $requestId, ?string $replyTo = null)
    {
        $this->trackingData = $trackingData;
        $this->videoData = $videoData;
        $this->requestId = $requestId;
        $this->replyTo = $replyTo;
    }

    /**
     * Execute the job - Process matching and publish results
     */
    public function handle(MatchingService $matchingService): void
    {
        try {
            Log::info('Processing matching request', ['request_id' => $this->requestId]);

            // Perform the matching
            $result = $matchingService->compareTrackingAndVideo($this->trackingData, $this->videoData);

            $matchResult = [
                'request_id' => $this->requestId,
                'tracking_id' => $this->trackingData['id'],
                'video_id' => $this->videoData['id'],
                'match_score' => $result['score'],
                'confidence_level' => $result['confidence'],
                'details' => $result['reasons'],
                'processed_at' => now()->toISOString(),
                'should_store' => $result['score'] >= 60,
                'global_match_id' => $result['score'] >= 60 ? 'match_' . uniqid() : null
            ];

            // Pattern 1: Send response back to requesting backend
            if ($this->replyTo) {
                $this->publishToQueue($this->replyTo, $matchResult);
            }

            // Pattern 2: Publish event for all subscribers
            $this->publishEvent('match.processed', $matchResult);

            // Pattern 3: Store in database for central hub functionality
            if ($result['score'] >= 60) {
                $this->storeMatch($matchResult);
                $this->publishEvent('match.found', $matchResult);
            }

            Log::info('Matching request processed successfully', ['request_id' => $this->requestId, 'score' => $result['score']]);

        } catch (\Exception $e) {
            Log::error('Failed to process matching request', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage()
            ]);

            // Publish error event
            $this->publishEvent('match.error', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
                'processed_at' => now()->toISOString()
            ]);

            throw $e; // Re-throw for queue retry mechanism
        }
    }

    /**
     * Publish message to specific queue (Pattern 1: Direct Response)
     */
    private function publishToQueue(string $queue, array $data): void
    {
        // This would use your RabbitMQ client to publish to specific queue
        // Example: Send response back to requesting backend
        Log::info('Publishing to queue', ['queue' => $queue, 'data' => $data]);
    }

    /**
     * Publish event to exchange (Pattern 2: Event Broadcasting)
     */
    private function publishEvent(string $eventType, array $data): void
    {
        $eventData = [
            'event_type' => $eventType,
            'data' => $data,
            'published_at' => now()->toISOString()
        ];

        // This would publish to RabbitMQ exchange for all subscribers
        Log::info('Publishing event', ['event_type' => $eventType, 'data' => $eventData]);
    }

    /**
     * Store match in database (Pattern 3: Central Hub)
     */
    private function storeMatch(array $matchResult): void
    {
        // Store in database for central hub functionality
        Log::info('Storing match in central database', ['match_id' => $matchResult['global_match_id']]);
    }
}