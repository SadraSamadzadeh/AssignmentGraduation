<?php

namespace Tests\Unit;

use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use App\Models\GlobalMatches;
use App\Models\TeamMapping;
use App\Services\MatchCoordinator;
use App\Services\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MatchCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    protected MatchCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = app(MatchCoordinator::class);
        Log::spy();
    }

    public function test_coordinator_can_be_instantiated()
    {
        $this->assertInstanceOf(MatchCoordinator::class, $this->coordinator);
    }

    public function test_match_tracking_to_videos_with_no_videos_returns_null()
    {
        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => now()
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertNull($result);
    }

    public function test_match_video_to_tracking_with_no_tracking_returns_null()
    {
        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => now()
        ]);

        $result = $this->coordinator->matchVideoToTracking($video);

        $this->assertNull($result);
    }

    public function test_creates_match_with_similar_tracking_and_video()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(10, 0, 0);
        $endTime = $eventDate->copy()->setTime(12, 0, 0);

        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Barcelona',
            'duration_minutes' => 120
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Barcelona',
            'duration_minutes' => 120
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertInstanceOf(GlobalMatches::class, $result);
        $this->assertEquals($tracking->tracking_id, $result->tracking_id);
        $this->assertEquals($video->video_id, $result->video_id);
    }

    public function test_updates_tracking_and_video_status_after_match()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(10, 0, 0);
        $endTime = $eventDate->copy()->setTime(12, 0, 0);

        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Real Madrid',
            'duration_minutes' => 120
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Real Madrid',
            'duration_minutes' => 120
        ]);

        $videoId = $video->id;

        $this->coordinator->matchTrackingToVideos($tracking);

        // Re-fetch tracking from database
        $trackingAfter = TrackingDashboard::find($tracking->id);

        // Video is deleted after match (business logic), so check it doesn't exist
        $videoAfter = VideoDashboard::find($videoId);

        $this->assertEquals('matched', $trackingAfter->status);
        $this->assertNull($videoAfter); // Video is deleted after successful match
    }

    public function test_skips_already_matched_tracking()
    {
        $tracking = TrackingDashboard::factory()->create([
            'status' => 'matched',
            'event_date' => now()
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => now()
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertNull($result);
    }

    public function test_skips_already_matched_video()
    {
        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => now()
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'matched',
            'event_date' => now()
        ]);

        $result = $this->coordinator->matchVideoToTracking($video);

        $this->assertNull($result);
    }

    public function test_stores_match_score_in_global_matches()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(14, 0, 0);
        $endTime = $eventDate->copy()->setTime(16, 0, 0);

        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Chelsea',
            'duration_minutes' => 120
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Chelsea',
            'duration_minutes' => 120
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertNotNull($result->match_score);
        $this->assertGreaterThan(0, $result->match_score);
    }

    public function test_creates_unique_global_match_id()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(15, 0, 0);
        $endTime = $eventDate->copy()->setTime(17, 0, 0);

        $tracking1 = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Arsenal',
            'duration_minutes' => 120
        ]);

        $video1 = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Arsenal',
            'duration_minutes' => 120
        ]);

        $tracking2 = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate->copy()->addDay(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Liverpool',
            'duration_minutes' => 120
        ]);

        $video2 = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate->copy()->addDay(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Liverpool',
            'duration_minutes' => 120
        ]);

        $match1 = $this->coordinator->matchTrackingToVideos($tracking1);
        $match2 = $this->coordinator->matchTrackingToVideos($tracking2);

        $this->assertNotEquals($match1->global_match_id, $match2->global_match_id);
    }

    public function test_uses_team_mapping_when_available()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(16, 0, 0);
        $endTime = $eventDate->copy()->setTime(18, 0, 0);

        TeamMapping::factory()->create([
            'primeplay_team_name' => 'Man United',
            'video_team_name' => 'Manchester United',
            'status' => 'active'
        ]);

        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Man United',
            'duration_minutes' => 120
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Manchester United',
            'duration_minutes' => 120
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertInstanceOf(GlobalMatches::class, $result);
    }

    public function test_match_stores_matched_at_timestamp()
    {
        $eventDate = now();
        $startTime = $eventDate->copy()->setTime(17, 0, 0);
        $endTime = $eventDate->copy()->setTime(19, 0, 0);

        $tracking = TrackingDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'team_name' => 'Tottenham',
            'duration_minutes' => 120
        ]);

        $video = VideoDashboard::factory()->create([
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'home_club_name' => 'Tottenham',
            'duration_minutes' => 120
        ]);

        $result = $this->coordinator->matchTrackingToVideos($tracking);

        $this->assertNotNull($result->matched_at);
        $this->assertInstanceOf(\DateTime::class, $result->matched_at);
    }
}
