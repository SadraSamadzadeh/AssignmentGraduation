<?php

namespace Database\Factories;

use App\Models\VideoDashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoDashboardFactory extends Factory
{
    protected $model = VideoDashboard::class;

    public function definition(): array
    {
        $eventDate = fake()->dateTimeBetween('-30 days', '+30 days');
        $startTime = clone $eventDate;
        $startTime->modify('+' . fake()->numberBetween(8, 18) . ' hours');
        $endTime = clone $startTime;
        $endTime->modify('+' . fake()->numberBetween(60, 180) . ' minutes');

        $videoId = fake()->unique()->uuid();
        
        return [
            'video_id' => $videoId,
            'video_reference' => 'VID-' . fake()->unique()->randomNumber(8),
            'video_data' => [
                'id' => $videoId,
                'videoId' => fake()->uuid(),
                'eventDate' => $eventDate->format('Y-m-d'),
                'startTime' => $startTime->format('H:i:s'),
                'endTime' => $endTime->format('H:i:s'),
                'homeClub' => fake()->company(),
                'awayClub' => fake()->company(),
                'fieldName' => 'Field ' . fake()->numberBetween(1, 10),
                'starting_at' => [
                    'date' => $startTime->format('Y-m-d H:i:s'),
                ],
                'timezone' => 'UTC',
            ],
            'source_system' => 'video_platform',
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_minutes' => (int) (($endTime->getTimestamp() - $startTime->getTimestamp()) / 60),
            'home_club_name' => fake()->company(),
            'away_club_name' => fake()->company(),
            'field_name' => 'Field ' . fake()->numberBetween(1, 10),
            'is_training' => fake()->boolean(20),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'matched',
        ]);
    }

    public function unmatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'unmatched',
        ]);
    }
}
