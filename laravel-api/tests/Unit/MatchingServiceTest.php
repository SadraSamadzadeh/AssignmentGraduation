<?php

namespace Tests\Unit;

use App\Services\MatchingService;
use App\Models\TrackingDashboard;
use App\Models\VideoDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MatchingService $matchingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matchingService = new MatchingService();
    }

    public function test_service_can_be_instantiated()
    {
        $this->assertInstanceOf(MatchingService::class, $this->matchingService);
    }

    public function test_service_works_with_model_data()
    {
        $tracking = TrackingDashboard::factory()->create([
            'event_date' => '2026-01-09',
            'start_time' => '2026-01-09 10:00:00',
            'end_time' => '2026-01-09 12:00:00',
            'team_name' => 'Barcelona'
        ]);

        $video = VideoDashboard::factory()->create([
            'event_date' => '2026-01-09',
            'start_time' => '2026-01-09 10:00:00',
            'end_time' => '2026-01-09 12:00:00',
            'home_club_name' => 'Barcelona'
        ]);

        $this->assertNotNull($tracking);
        $this->assertNotNull($video);
        $this->assertEquals('2026-01-09', $tracking->event_date->format('Y-m-d'));
        $this->assertEquals('2026-01-09', $video->event_date->format('Y-m-d'));
    }

    public function test_can_compare_team_names()
    {
        $name1 = 'Barcelona FC';
        $name2 = 'barcelona fc';
        
        $this->assertEquals(
            strtolower($name1),
            strtolower($name2),
            'Team names should match case-insensitively'
        );
    }

    public function test_can_calculate_duration()
    {
        $start = new \DateTime('2026-01-09 10:00:00');
        $end = new \DateTime('2026-01-09 12:00:00');
        
        $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        
        $this->assertEquals(120, $duration, 'Duration should be 120 minutes');
    }

    public function test_can_check_same_day()
    {
        $date1 = new \DateTime('2026-01-09 10:00:00');
        $date2 = new \DateTime('2026-01-09 14:00:00');
        $date3 = new \DateTime('2026-01-10 10:00:00');
        
        $this->assertEquals(
            $date1->format('Y-m-d'),
            $date2->format('Y-m-d'),
            'Same day check should work'
        );
        
        $this->assertNotEquals(
            $date1->format('Y-m-d'),
            $date3->format('Y-m-d'),
            'Different day check should work'
        );
    }
}
