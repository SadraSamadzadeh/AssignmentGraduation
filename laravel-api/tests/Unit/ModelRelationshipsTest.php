<?php

namespace Tests\Unit;

use App\Models\GlobalMatches;
use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for model relationships and advanced functionality
 */
class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_match_belongs_to_tracking()
    {
        $tracking = TrackingDashboard::factory()->create();
        $video = VideoDashboard::factory()->create();

        $match = GlobalMatches::create([
            'global_match_id' => 'test_match_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'pending_review',
            'confidence_level' => 'medium',
            'match_score' => 75.5
        ]);

        $this->assertInstanceOf(TrackingDashboard::class, $match->tracking);
        $this->assertEquals($tracking->tracking_id, $match->tracking->tracking_id);
    }

    public function test_tracking_dashboard_has_players()
    {
        $tracking = TrackingDashboard::factory()->create();

        Player::factory()->count(3)->create([
            'tracking_dashboard_id' => $tracking->id
        ]);

        $this->assertCount(3, $tracking->players);
    }

    public function test_player_belongs_to_tracking()
    {
        $tracking = TrackingDashboard::factory()->create();
        
        $player = Player::create([
            'tracking_dashboard_id' => $tracking->id,
            'player_name' => 'John Doe',
            'device_id' => 'device_' . time(),
            'dataset_id' => 1000,
            'jersey_number' => 10,
            'position' => 'Forward',
            'player_data' => []
        ]);

        $this->assertInstanceOf(TrackingDashboard::class, $player->trackingDashboard);
        $this->assertEquals($tracking->id, $player->trackingDashboard->id);
    }

    public function test_tracking_dashboard_scope_unmatched()
    {
        TrackingDashboard::factory()->create(['status' => 'unmatched']);
        TrackingDashboard::factory()->create(['status' => 'unmatched']);
        TrackingDashboard::factory()->create(['status' => 'matched']);

        $unmatched = TrackingDashboard::unmatched()->get();

        $this->assertCount(2, $unmatched);
    }

    public function test_video_dashboard_scope_unmatched()
    {
        VideoDashboard::factory()->create(['status' => 'unmatched']);
        VideoDashboard::factory()->create(['status' => 'matched']);
        VideoDashboard::factory()->create(['status' => 'unmatched']);

        $unmatched = VideoDashboard::unmatched()->get();

        $this->assertCount(2, $unmatched);
    }

    public function test_tracking_dashboard_scope_matched()
    {
        TrackingDashboard::factory()->create(['status' => 'matched']);
        TrackingDashboard::factory()->create(['status' => 'unmatched']);
        TrackingDashboard::factory()->create(['status' => 'matched']);

        $matched = TrackingDashboard::matched()->get();

        $this->assertCount(2, $matched);
    }

    public function test_video_dashboard_scope_matched()
    {
        VideoDashboard::factory()->create(['status' => 'matched']);
        VideoDashboard::factory()->create(['status' => 'matched']);
        VideoDashboard::factory()->create(['status' => 'unmatched']);

        $matched = VideoDashboard::matched()->get();

        $this->assertCount(2, $matched);
    }

    public function test_tracking_dashboard_by_event_date()
    {
        $today = now();
        $yesterday = now()->subDay();

        TrackingDashboard::factory()->create(['event_date' => $today]);
        TrackingDashboard::factory()->create(['event_date' => $today]);
        TrackingDashboard::factory()->create(['event_date' => $yesterday]);

        $todayRecords = TrackingDashboard::byEventDate($today)->get();

        $this->assertCount(2, $todayRecords);
    }

    public function test_video_dashboard_by_event_date()
    {
        $today = now();
        $tomorrow = now()->addDay();

        VideoDashboard::factory()->create(['event_date' => $today]);
        VideoDashboard::factory()->create(['event_date' => $tomorrow]);
        VideoDashboard::factory()->create(['event_date' => $today]);

        $todayRecords = VideoDashboard::byEventDate($today)->get();

        $this->assertCount(2, $todayRecords);
    }

    public function test_global_matches_confirmed_scope()
    {
        $tracking = TrackingDashboard::factory()->create();
        $video1 = VideoDashboard::factory()->create();
        $video2 = VideoDashboard::factory()->create();
        $video3 = VideoDashboard::factory()->create();

        GlobalMatches::create([
            'global_match_id' => 'match_1_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video1->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'confirmed',
            'confidence_level' => 'high',
            'match_score' => 95.0
        ]);

        GlobalMatches::create([
            'global_match_id' => 'match_2_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video2->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'pending_review',
            'confidence_level' => 'medium',
            'match_score' => 75.0
        ]);

        GlobalMatches::create([
            'global_match_id' => 'match_3_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video3->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'confirmed',
            'confidence_level' => 'high',
            'match_score' => 90.0
        ]);

        $confirmed = GlobalMatches::confirmed()->get();

        $this->assertCount(2, $confirmed);
    }

    public function test_global_matches_pending_scope()
    {
        $tracking = TrackingDashboard::factory()->create();
        $video1 = VideoDashboard::factory()->create();
        $video2 = VideoDashboard::factory()->create();

        GlobalMatches::create([
            'global_match_id' => 'match_pending_1_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video1->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'pending_review',
            'confidence_level' => 'medium',
            'match_score' => 70.0
        ]);

        GlobalMatches::create([
            'global_match_id' => 'match_pending_2_' . time(),
            'tracking_id' => $tracking->tracking_id,
            'video_id' => $video2->video_id,
            'tracking_data' => [],
            'video_data' => [],
            'status' => 'confirmed',
            'confidence_level' => 'high',
            'match_score' => 95.0
        ]);

        $pending = GlobalMatches::pendingReview()->get();

        $this->assertCount(1, $pending);
    }

    public function test_tracking_dashboard_stores_complex_tracking_data()
    {
        $complexData = [
            'teams' => ['home' => 'TeamA', 'away' => 'TeamB'],
            'stats' => ['goals' => 3, 'shots' => 15],
            'players' => [
                ['id' => 1, 'name' => 'Player1'],
                ['id' => 2, 'name' => 'Player2']
            ]
        ];

        $tracking = TrackingDashboard::factory()->create([
            'tracking_data' => $complexData
        ]);

        $this->assertEquals($complexData, $tracking->fresh()->tracking_data);
    }

    public function test_video_dashboard_stores_complex_video_data()
    {
        $complexData = [
            'match_info' => ['home' => 'TeamX', 'away' => 'TeamY'],
            'video_quality' => '1080p',
            'segments' => [
                ['start' => '00:00', 'end' => '45:00'],
                ['start' => '45:00', 'end' => '90:00']
            ]
        ];

        $video = VideoDashboard::factory()->create([
            'video_data' => $complexData
        ]);

        $this->assertEquals($complexData, $video->fresh()->video_data);
    }

    public function test_player_stores_complex_player_data()
    {
        $tracking = TrackingDashboard::factory()->create();
        
        $playerData = [
            'physical' => ['height' => 180, 'weight' => 75],
            'stats' => ['goals' => 5, 'assists' => 3],
            'tracking_points' => [[0, 0], [10, 20], [30, 40]]
        ];

        $player = Player::create([
            'tracking_dashboard_id' => $tracking->id,
            'player_name' => 'Complex Player',
            'device_id' => 'device_complex_' . time(),
            'dataset_id' => 2000,
            'jersey_number' => 7,
            'position' => 'Midfielder',
            'player_data' => $playerData
        ]);

        $this->assertEquals($playerData, $player->fresh()->player_data);
    }
}
