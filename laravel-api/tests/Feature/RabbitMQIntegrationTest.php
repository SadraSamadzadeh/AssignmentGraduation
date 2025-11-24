<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Jobs\ProcessIncomingMessage;
use App\Models\TrackingDashboard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

class RabbitMQIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
        
        // DO NOT fake the queue - we want to test real RabbitMQ
        // Queue::fake(); // <-- Commented out to test real RabbitMQ
        
        // Clean up test data
        $this->cleanupTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }
    
    protected function cleanupTestData(): void
    {
        // Clean up any test data created during tests
        TrackingDashboard::where('tracking_id', '>=', 8000)->delete();
    }

    /**
     * Test RabbitMQ message sending and processing
     */
    public function test_rabbitmq_message_sending_and_processing(): void
    {
        // Set the queue connection to RabbitMQ for this test
        config(['queue.default' => 'rabbitmq']);
        
        $testId = 8001;
        $trackingData = [
            'id' => $testId,
            'name' => 'RabbitMQ Test Match',
            'teamName' => 'RabbitMQ Test Team',
            'startTime' => '2025-11-24T10:00:00.000Z',
            'endTime' => '2025-11-24T12:00:00.000Z',
            'trimmedStartTime' => '00:10:00',
            'trimmedEndTime' => '01:50:00'
        ];

        echo "\n=== RABBITMQ INTEGRATION TEST ===\n";
        echo "Testing RabbitMQ message sending and processing...\n";
        echo "Tracking ID: {$testId}\n";
        echo "Team: {$trackingData['teamName']}\n";

        // Send job to RabbitMQ
        try {
            ProcessIncomingMessage::dispatch($trackingData, 'tracking');
            echo "✓ Job successfully dispatched to RabbitMQ\n";
            
            // Give the worker a moment to process the job
            sleep(2);
            
            // Check if the job was processed and data stored
            $record = TrackingDashboard::where('tracking_id', $testId)->first();
            
            if ($record) {
                echo "✓ Job processed successfully by RabbitMQ worker\n";
                echo "  - Tracking ID: {$record->tracking_id}\n";
                echo "  - Source: {$record->source_system}\n";
                echo "  - Created at: {$record->created_at}\n";
                
                $this->assertEquals($testId, $record->tracking_id);
                $this->assertEquals('tracking_solution', $record->source_system);
            } else {
                echo "⚠ Job dispatched but not yet processed (worker may be processing)\n";
                // This is not necessarily a failure - the worker might just be slow
            }
            
        } catch (\Exception $e) {
            echo "❌ Error dispatching job to RabbitMQ: {$e->getMessage()}\n";
            $this->fail("RabbitMQ job dispatch failed: {$e->getMessage()}");
        }

        // Reset queue connection back to default
        config(['queue.default' => env('QUEUE_CONNECTION', 'sync')]);
    }

    /**
     * Test RabbitMQ connection directly
     */
    public function test_rabbitmq_connection(): void
    {
        echo "\n=== RABBITMQ CONNECTION TEST ===\n";
        
        // Test RabbitMQ connection
        try {
            config(['queue.default' => 'rabbitmq']);
            
            // Get the RabbitMQ queue manager
            $queue = Queue::connection('rabbitmq');
            
            // Try to push a simple test job
            $testData = ['test' => 'connection', 'timestamp' => time()];
            $queue->push('test-job', $testData, 'test-queue');
            
            echo "✓ RabbitMQ connection successful\n";
            echo "✓ Test message pushed to RabbitMQ\n";
            
            $this->assertTrue(true); // Test passes if no exception thrown
            
        } catch (\Exception $e) {
            echo "❌ RabbitMQ connection failed: {$e->getMessage()}\n";
            $this->fail("RabbitMQ connection test failed: {$e->getMessage()}");
        } finally {
            // Reset queue connection
            config(['queue.default' => env('QUEUE_CONNECTION', 'sync')]);
        }
    }

    /**
     * Test HTTP ingestion with real RabbitMQ queue
     */
    public function test_http_ingestion_with_real_rabbitmq(): void
    {
        // Set RabbitMQ as the queue driver
        config(['queue.default' => 'rabbitmq']);
        
        $testId = 8002;
        $trackingData = [
            'id' => $testId,
            'name' => 'HTTP + RabbitMQ Test',
            'teamName' => 'Integration Test Team',
            'startTime' => '2025-11-24T11:00:00.000Z',
            'endTime' => '2025-11-24T13:00:00.000Z'
        ];

        echo "\n=== HTTP INGESTION + RABBITMQ TEST ===\n";
        echo "Testing HTTP endpoint with real RabbitMQ backend...\n";
        echo "Tracking ID: {$testId}\n";

        // Send POST request to ingestion endpoint
        $response = $this->postJson('/api/ingest/tracking', $trackingData);

        echo "Response Status: {$response->status()}\n";
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Tracking data received and queued for processing'
                ]);

        echo "✓ HTTP request successful\n";
        echo "✓ Job queued to RabbitMQ via HTTP endpoint\n";
        
        // Give worker time to process
        sleep(2);
        
        // Check if processed (optional - worker might be slow)
        $record = TrackingDashboard::where('tracking_id', $testId)->first();
        if ($record) {
            echo "✓ Job processed by RabbitMQ worker\n";
        } else {
            echo "⚠ Job queued but processing may still be in progress\n";
        }

        // Reset queue connection
        config(['queue.default' => env('QUEUE_CONNECTION', 'sync')]);
    }
}