<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_user()
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->name);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $user->email
        ]);
    }

    public function test_tracking_dashboard_factory_creates_record()
    {
        $tracking = TrackingDashboard::factory()->create();

        $this->assertInstanceOf(TrackingDashboard::class, $tracking);
        $this->assertNotNull($tracking->tracking_id);
        $this->assertNotNull($tracking->event_date);
        $this->assertDatabaseHas('tracking_dashboard', [
            'id' => $tracking->id,
            'tracking_id' => $tracking->tracking_id
        ]);
    }

    public function test_video_dashboard_factory_creates_record()
    {
        $video = VideoDashboard::factory()->create();

        $this->assertInstanceOf(VideoDashboard::class, $video);
        $this->assertNotNull($video->video_id);
        $this->assertNotNull($video->event_date);
        $this->assertDatabaseHas('video_dashboard', [
            'id' => $video->id,
            'video_id' => $video->video_id
        ]);
    }

    public function test_can_create_multiple_tracking_records()
    {
        $trackings = TrackingDashboard::factory()->count(5)->create();

        $this->assertCount(5, $trackings);
        $this->assertEquals(5, TrackingDashboard::count());
    }

    public function test_can_create_multiple_video_records()
    {
        $videos = VideoDashboard::factory()->count(5)->create();

        $this->assertCount(5, $videos);
        $this->assertEquals(5, VideoDashboard::count());
    }

    public function test_factory_generates_unique_tracking_ids()
    {
        $tracking1 = TrackingDashboard::factory()->create();
        $tracking2 = TrackingDashboard::factory()->create();

        $this->assertNotEquals($tracking1->tracking_id, $tracking2->tracking_id);
    }

    public function test_factory_generates_unique_video_ids()
    {
        $video1 = VideoDashboard::factory()->create();
        $video2 = VideoDashboard::factory()->create();

        $this->assertNotEquals($video1->video_id, $video2->video_id);
    }

    public function test_factory_can_override_attributes()
    {
        $tracking = TrackingDashboard::factory()->create([
            'team_name' => 'Custom Team',
            'status' => 'matched'
        ]);

        $this->assertEquals('Custom Team', $tracking->team_name);
        $this->assertEquals('matched', $tracking->status);
    }
}
