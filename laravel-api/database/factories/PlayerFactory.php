<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\TrackingDashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'player_name' => fake()->name(),
            'device_id' => fake()->unique()->uuid(),
            'dataset_id' => fake()->numberBetween(1000, 9999),
            'tracking_dashboard_id' => TrackingDashboard::factory(),
            'player_data' => [
                'speed' => fake()->randomFloat(2, 0, 30),
                'distance' => fake()->randomFloat(2, 0, 15000),
                'heart_rate' => fake()->numberBetween(60, 200),
            ],
            'jersey_number' => fake()->numberBetween(1, 99),
            'position' => fake()->randomElement(['Forward', 'Midfielder', 'Defender', 'Goalkeeper']),
            'team_name' => fake()->company(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
