<?php

namespace Tests\Unit;

use App\Jobs\ProcessPrimeplayMessage;
use App\Jobs\ProcessVideoMessage;
use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MessageProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_primeplay_message_stores_tracking_data()
    {
        $messageData = [
            'tracking_id' => 'track_001',
            'team_name' => 'Barcelona',
            'event_date' => '2026-01-09',
            'start_time' => '2026-01-09 10:00:00',
            'end_time' => '2026-01-09 12:00:00',
        ];

        $job = new ProcessPrimeplayMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('tracking_dashboard', [
            'tracking_id' => 'track_001',
            'team_name' => 'Barcelona',
            'status' => 'unmatched'
        ]);
    }

    public function test_primeplay_message_handles_minimal_data()
    {
        $messageData = [
            'tracking_id' => 'track_002',
        ];

        $job = new ProcessPrimeplayMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('tracking_dashboard', [
            'tracking_id' => 'track_002'
        ]);
    }

    public function test_video_message_stores_video_data()
    {
        $messageData = [
            'video_id' => 'video_001',
            'home_club_name' => 'Real Madrid',
            'away_club_name' => 'Barcelona',
            'event_date' => '2026-01-09',
            'start_time' => '2026-01-09 10:00:00',
            'end_time' => '2026-01-09 12:00:00',
        ];

        $job = new ProcessVideoMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('video_dashboard', [
            'video_id' => 'video_001',
            'home_club_name' => 'Real Madrid',
            'status' => 'unmatched'
        ]);
    }

    public function test_video_message_handles_minimal_data()
    {
        $messageData = [
            'video_id' => 'video_002',
        ];

        $job = new ProcessVideoMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('video_dashboard', [
            'video_id' => 'video_002'
        ]);
    }

    public function test_duplicate_tracking_id_updates_existing_record()
    {
        TrackingDashboard::create([
            'tracking_id' => 'track_003',
            'team_name' => 'Old Team',
            'status' => 'unmatched',
            'event_date' => '2026-01-09',
            'source_system' => 'primeplay',
            'tracking_data' => [],
            'received_at' => now()
        ]);

        $messageData = [
            'tracking_id' => 'track_003',
            'team_name' => 'New Team',
            'event_date' => '2026-01-09',
        ];

        $job = new ProcessPrimeplayMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('tracking_dashboard', [
            'tracking_id' => 'track_003',
            'team_name' => 'New Team'
        ]);

        $this->assertEquals(1, TrackingDashboard::where('tracking_id', 'track_003')->count());
    }

    public function test_duplicate_video_id_updates_existing_record()
    {
        VideoDashboard::create([
            'video_id' => 'video_003',
            'home_club_name' => 'Old Club',
            'status' => 'unmatched',
            'event_date' => '2026-01-09',
            'source_system' => 'video',
            'video_data' => [],
            'received_at' => now()
        ]);

        $messageData = [
            'video_id' => 'video_003',
            'home_club_name' => 'New Club',
            'event_date' => '2026-01-09',
        ];

        $job = new ProcessVideoMessage($messageData);
        $job->handle();

        $this->assertDatabaseHas('video_dashboard', [
            'video_id' => 'video_003',
            'home_club_name' => 'New Club'
        ]);

        $this->assertEquals(1, VideoDashboard::where('video_id', 'video_003')->count());
    }
}
