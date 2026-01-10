<?php

namespace Database\Factories;

use App\Models\TeamMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeamMappingFactory extends Factory
{
    protected $model = TeamMapping::class;

    public function definition(): array
    {
        return [
            'video_team_id' => $this->faker->uuid(),
            'video_team_name' => $this->faker->company() . ' FC',
            'primeplay_team_id' => $this->faker->uuid(),
            'primeplay_team_name' => $this->faker->company() . ' United',
            'match_details' => [
                'confidence' => $this->faker->randomFloat(2, 0.85, 1.0),
                'created_from_match' => true,
            ],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
