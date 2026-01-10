<?php

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class ConnectedAccountFactory extends Factory
{
    protected $model = ConnectedAccount::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(['primeplay', 'video_dashboard']);
        $expiresAt = now()->addHours($this->faker->numberBetween(1, 48));

        return [
            'user_id' => User::factory(),
            'provider' => $provider,
            'provider_user_id' => $this->faker->uuid(),
            'provider_username' => $this->faker->userName(),
            'provider_email' => $this->faker->safeEmail(),
            'access_token' => $this->encryptToken($this->faker->sha256()),
            'refresh_token' => $this->encryptToken($this->faker->sha256()),
            'token_expires_at' => $expiresAt,
            'is_primary' => false,
            'status' => 'active',
            'last_synced_at' => now(),
            'metadata' => [
                'connected_at' => now()->toIso8601String(),
                'provider_name' => ucfirst(str_replace('_', ' ', $provider)),
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Safely encrypt token, falling back to plain text if encryption fails
     */
    private function encryptToken(string $token): string
    {
        try {
            return Crypt::encryptString($token);
        } catch (\Exception $e) {
            // In testing environment without proper APP_KEY, store plain token
            return $token;
        }
    }
}
