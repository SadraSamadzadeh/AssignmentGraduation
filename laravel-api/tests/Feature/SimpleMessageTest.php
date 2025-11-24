<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SimpleMessageTest extends TestCase
{
    /**
     * Test basic API endpoint functionality without database refresh
     */
    public function test_api_health_endpoint(): void
    {
        $response = $this->get('/api/health');
        
        $response->assertStatus(200)
                ->assertJson([
                    'status' => 'ok',
                    'message' => 'Laravel Matching API is running'
                ]);
    }

    /**
     * Test tracking data ingestion endpoint
     */
    public function test_tracking_ingestion_endpoint(): void
    {
        $trackingData = [
            'id' => 999,
            'name' => 'Test Match',
            'teamName' => 'Test Team',
            'startTime' => '2025-11-24T10:00:00.000Z',
            'endTime' => '2025-11-24T12:00:00.000Z'
        ];

        $response = $this->postJson('/api/ingest/tracking', $trackingData);
        
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Tracking data received and queued for processing'
                ]);
    }

    /**
     * Test database connection
     */
    public function test_database_connection(): void
    {
        try {
            $result = DB::select('SELECT 1 as test');
            $this->assertEquals(1, $result[0]->test);
        } catch (\Exception $e) {
            $this->fail('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Test dashboard stats endpoint
     */
    public function test_dashboard_stats_endpoint(): void
    {
        $response = $this->get('/api/dashboard/stats');
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'statistics' => [
                        'tracking' => [
                            'total_in_database',
                            'cached_unmatched'
                        ],
                        'video' => [
                            'total_in_database',
                            'cached_unmatched'
                        ]
                    ],
                    'timestamp'
                ]);
    }
}