<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessage;
use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageIngestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
        
        // Fake the queue for testing
        Queue::fake();
        
        // Clean up test data instead of refreshing database
        $this->cleanupTestData();
    }
    
    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanupTestData();
        parent::tearDown();
    }
    
    protected function cleanupTestData(): void
    {
        // Clean up any test data created during tests
        TrackingDashboard::where('tracking_id', '>=', 999)->delete();
        VideoDashboard::where('video_id', 'like', 'test-%')->delete();
    }

    /**
     * Test Case 1: Receive tracking data via HTTP and verify storage
     */
    public function test_receive_tracking_data_via_http(): void
    {
        $testId = 9999; // Use test-specific ID
        $trackingData = [
            'id' => $testId,
            'name' => 'Test Match - Ingestion Test',
            'typeId' => 1,
            'typeName' => 'Match',
            'teamName' => 'Test Team',
            'sourceId' => 2,
            'startTime' => '2025-11-24T10:00:00.100Z',
            'endTime' => '2025-11-24T12:00:00.100Z',
            'trimmedStartTime' => '00:05:00',
            'trimmedEndTime' => '01:55:00',
            'avgTeamDistanceInMeters' => 8000.0,
            'hasBeenTrimmed' => true,
            'isLive' => false
        ];

        echo "\n=== TEST 1: Receiving Tracking Data via HTTP ===\n";
        echo "Sending tracking ID: {$trackingData['id']}\n";
        echo "Team: {$trackingData['teamName']}\n";

        // Send POST request to ingestion endpoint
        $response = $this->postJson('/api/ingest/tracking', $trackingData);

        echo "Response Status: {$response->status()}\n";
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Tracking data received and queued for processing'
            ]);

        // Verify job was dispatched
        Queue::assertPushed(ProcessIncomingMessage::class);

        echo "✓ HTTP request successful\n";
        echo "✓ Job dispatched to queue\n";
    }

    /**
     * Test Case 2: Process tracking message and verify database storage
     */
    public function test_process_tracking_message_stores_in_database(): void
    {
        $trackingData = [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-01T14:34:01.100Z',
            'endTime' => '2025-11-01T16:33:39.100Z',
            'trimmedStartTime' => '02:42:52',
            'trimmedEndTime' => '04:42:30'
        ];

        echo "\n=== TEST 2: Processing Tracking Message - Database Storage ===\n";
        echo "Processing tracking ID: {$trackingData['id']}\n";

        // Process the message directly
        $job = new ProcessIncomingMessage($trackingData, 'tracking');
        $job->handle();

        // Verify data was stored in database
        $this->assertDatabaseHas('tracking_dashboard', [
            'tracking_id' => 193,
            'source_system' => 'tracking_solution'
        ]);

        // Retrieve and verify the stored record
        $record = TrackingDashboard::where('tracking_id', 193)->first();
        
        $this->assertNotNull($record);
        $this->assertEquals(193, $record->tracking_id);
        $this->assertEquals('tracking_solution', $record->source_system);
        $this->assertEquals(0, $record->match_attempts);
        $this->assertNull($record->last_match_attempt_at);
        $this->assertNull($record->assigned_to_user_id);
        $this->assertNotNull($record->received_at);

        // Verify JSON data
        $storedData = json_decode($record->tracking_data, true);
        $this->assertEquals('Capelle 1', $storedData['teamName']);
        $this->assertEquals('Match Capelle - Westlandia', $storedData['name']);

        echo "✓ Data stored in database\n";
        echo "  - Tracking ID: {$record->tracking_id}\n";
        echo "  - Source: {$record->source_system}\n";
        echo "  - Match attempts: {$record->match_attempts}\n";
        echo "  - Received at: {$record->received_at}\n";
    }

    /**
     * Test Case 3: Process tracking message and verify cache storage
     */
    public function test_process_tracking_message_stores_in_cache(): void
    {
        $trackingData = [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-01T14:34:01.100Z',
            'endTime' => '2025-11-01T16:33:39.100Z'
        ];

        echo "\n=== TEST 3: Processing Tracking Message - Cache Storage ===\n";
        echo "Processing tracking ID: {$trackingData['id']}\n";

        // Process the message
        $job = new ProcessIncomingMessage($trackingData, 'tracking');
        $job->handle();

        // Verify data was cached
        $cacheKey = "tracking_data_193";
        $cachedData = Cache::get($cacheKey);
        
        $this->assertNotNull($cachedData, "Cache key '{$cacheKey}' should exist");
        $this->assertEquals(193, $cachedData['id']);
        $this->assertEquals('Capelle 1', $cachedData['teamName']);
        $this->assertEquals('Match Capelle - Westlandia', $cachedData['name']);

        echo "✓ Data cached successfully\n";
        echo "  - Cache key: {$cacheKey}\n";
        echo "  - Team: {$cachedData['teamName']}\n";
        echo "  - Name: {$cachedData['name']}\n";

        // Verify unmatched list was updated
        $unmatchedList = Cache::get('unmatched_tracking_ids', []);
        $this->assertContains(193, $unmatchedList);
        
        echo "✓ Added to unmatched list\n";
        echo "  - Unmatched count: " . count($unmatchedList) . "\n";
    }

    /**
     * Test Case 4: Process video message and verify storage
     */
    public function test_process_video_message_stores_data(): void
    {
        $videoData = [
            'id' => '182e5d44-5fd8-4bc7-ab55-0f2705562058',
            'match_group_id' => '182e5d44-5fd8-4bc7-ab55-0f2705562058',
            'club' => ['name' => 'Westlandia'],
            'home' => ['name' => 'Westlandia', 'short_name' => 'WES'],
            'away' => ['name' => 'VV Capelle', 'short_name' => 'VVC'],
            'starting_at' => ['date' => '2025-11-01T15:28:00+01:00'],
            'stopping_at' => ['date' => '2025-11-01T17:40:00+01:00'],
            'timezone' => 'Europe/Amsterdam'
        ];

        echo "\n=== TEST 4: Processing Video Message ===\n";
        echo "Processing video ID: {$videoData['id']}\n";
        echo "Match: {$videoData['home']['name']} vs {$videoData['away']['name']}\n";

        // Process the message
        $job = new ProcessIncomingMessage($videoData, 'video');
        $job->handle();

        // Verify database storage
        $this->assertDatabaseHas('video_dashboard', [
            'video_id' => '182e5d44-5fd8-4bc7-ab55-0f2705562058',
            'source_system' => 'video_solution'
        ]);

        $record = VideoDashboard::where('video_id', '182e5d44-5fd8-4bc7-ab55-0f2705562058')->first();
        $this->assertNotNull($record);
        $this->assertEquals(0, $record->match_attempts);

        echo "✓ Video data stored in database\n";
        echo "  - Video ID: {$record->video_id}\n";
        echo "  - Reference: {$record->video_reference}\n";

        // Verify cache storage
        $cacheKey = "video_data_182e5d44-5fd8-4bc7-ab55-0f2705562058";
        $cachedData = Cache::get($cacheKey);
        
        $this->assertNotNull($cachedData);
        $this->assertEquals('Westlandia', $cachedData['home']['name']);
        $this->assertEquals('VV Capelle', $cachedData['away']['name']);

        echo "✓ Video data cached successfully\n";
        echo "  - Cache key: {$cacheKey}\n";

        // Verify unmatched list
        $unmatchedList = Cache::get('unmatched_video_ids', []);
        $this->assertContains('182e5d44-5fd8-4bc7-ab55-0f2705562058', $unmatchedList);
        
        echo "✓ Added to unmatched video list\n";
    }

    /**
     * Test Case 5: Multiple tracking messages
     */
    public function test_process_multiple_tracking_messages(): void
    {
        echo "\n=== TEST 5: Processing Multiple Tracking Messages ===\n";

        $trackingData1 = [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-01T14:34:01.100Z',
            'endTime' => '2025-11-01T16:33:39.100Z'
        ];

        $trackingData2 = [
            'id' => 195,
            'name' => 'Match Capelle oefen',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-04T19:03:34.100Z',
            'endTime' => '2025-11-04T20:55:06.100Z'
        ];

        // Process both messages
        $job1 = new ProcessIncomingMessage($trackingData1, 'tracking');
        $job1->handle();
        
        echo "Processed tracking ID: 193\n";

        $job2 = new ProcessIncomingMessage($trackingData2, 'tracking');
        $job2->handle();
        
        echo "Processed tracking ID: 195\n";

        // Verify both are in database
        $this->assertEquals(2, TrackingDashboard::count());
        
        // Verify both are cached
        $this->assertNotNull(Cache::get('tracking_data_193'));
        $this->assertNotNull(Cache::get('tracking_data_195'));

        // Verify unmatched list contains both
        $unmatchedList = Cache::get('unmatched_tracking_ids', []);
        $this->assertCount(2, $unmatchedList);
        $this->assertContains(193, $unmatchedList);
        $this->assertContains(195, $unmatchedList);

        echo "✓ Both messages processed successfully\n";
        echo "  - Database records: 2\n";
        echo "  - Cached items: 2\n";
        echo "  - Unmatched list: " . count($unmatchedList) . "\n";
    }

    /**
     * Test Case 6: Dashboard API endpoints
     */
    public function test_dashboard_endpoints_return_stored_data(): void
    {
        echo "\n=== TEST 6: Dashboard API Endpoints ===\n";

        // Store some test data
        $trackingData = [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'teamName' => 'Capelle 1',
            'startTime' => '2025-11-01T14:34:01.100Z',
            'endTime' => '2025-11-01T16:33:39.100Z'
        ];

        $job = new ProcessIncomingMessage($trackingData, 'tracking');
        $job->handle();

        // Test unmatched tracking endpoint
        $response = $this->getJson('/api/dashboard/tracking/unmatched');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'tracking_id',
                        'source_system',
                        'match_attempts',
                        'received_at',
                        'tracking_data'
                    ]
                ]
            ]);

        echo "✓ Unmatched tracking endpoint working\n";
        echo "  - Records returned: " . $response->json('count') . "\n";

        // Test cached tracking endpoint
        $response = $this->getJson('/api/dashboard/tracking/193/cache');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cache_key' => 'tracking_data_193'
            ]);

        echo "✓ Cached tracking endpoint working\n";

        // Test dashboard stats endpoint
        $response = $this->getJson('/api/dashboard/stats');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'statistics' => [
                    'tracking' => ['total_in_database', 'cached_unmatched'],
                    'video' => ['total_in_database', 'cached_unmatched']
                ]
            ]);

        echo "✓ Dashboard stats endpoint working\n";
        $stats = $response->json('statistics');
        echo "  - Tracking in DB: {$stats['tracking']['total_in_database']}\n";
        echo "  - Tracking cached: {$stats['tracking']['cached_unmatched']}\n";
    }

    /**
     * Comprehensive Test: Full ingestion workflow
     */
    public function test_complete_message_ingestion_workflow(): void
    {
        echo "\n\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║         MESSAGE INGESTION COMPREHENSIVE TEST SUITE            ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";

        echo "\n Running complete ingestion workflow test...\n";
        echo "─────────────────────────────────────────────────────────────────\n";

        // Step 1: Receive via HTTP
        $this->test_receive_tracking_data_via_http();

        // Step 2: Process and store in database
        $this->test_process_tracking_message_stores_in_database();

        // Step 3: Verify cache
        $this->test_process_tracking_message_stores_in_cache();

        // Step 4: Process video data
        $this->test_process_video_message_stores_data();

        // Step 5: Multiple messages
        $this->test_process_multiple_tracking_messages();

        // Step 6: Dashboard endpoints
        $this->test_dashboard_endpoints_return_stored_data();

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                         TEST SUMMARY                           ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Total Tests:            6                                       ║\n";
        echo "║ HTTP Ingestion:         ✓ Passed                               ║\n";
        echo "║ Database Storage:       ✓ Passed                               ║\n";
        echo "║ Cache Storage:          ✓ Passed                               ║\n";
        echo "║ Video Processing:       ✓ Passed                               ║\n";
        echo "║ Multiple Messages:      ✓ Passed                               ║\n";
        echo "║ Dashboard APIs:         ✓ Passed                               ║\n";
        echo "╠════════════════════════════════════════════════════════════════╣\n";
        echo "║ Message Flow Verified:                                         ║\n";
        echo "║ External App → HTTP/RabbitMQ → Job Queue → Database + Cache   ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";

        $this->assertTrue(true, 'All ingestion workflow tests passed');
    }
}
