<?php

namespace Database\Factories;

use App\Models\TrackingDashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrackingDashboardFactory extends Factory
{
    protected $model = TrackingDashboard::class;

    public function definition(): array
    {
        $eventDate = fake()->dateTimeBetween('-30 days', '+30 days');
        $startTime = clone $eventDate;
        $startTime->modify('+' . fake()->numberBetween(8, 18) . ' hours');
        $endTime = clone $startTime;
        $endTime->modify('+' . fake()->numberBetween(60, 180) . ' minutes');

        $trackingId = fake()->unique()->numberBetween(100000, 999999);
        
        return [
            'tracking_id' => $trackingId,
            'tracking_reference' => 'TRK-' . fake()->unique()->randomNumber(8),
            'tracking_data' => [
                'id' => $trackingId,
                'matchId' => fake()->numberBetween(1000, 9999),
                'eventDate' => $eventDate->format('Y-m-d'),
                'startTime' => $startTime->format('H:i:s'),
                'endTime' => $endTime->format('H:i:s'),
                'datasetName' => fake()->word(),
                'teamName' => fake()->company(),
            ],
            'source_system' => 'primeplay',
            'status' => 'unmatched',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_minutes' => (int) (($endTime->getTimestamp() - $startTime->getTimestamp()) / 60),
            'dataset_name' => fake()->word(),
            'team_name' => fake()->company(),
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
