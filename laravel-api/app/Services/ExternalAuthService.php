<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalAuthService
{
    /**
     * Make an authenticated API call to an external service
     *
     * @param string $provider The provider (e.g., 'primeplay')
     * @param string $endpoint The API endpoint
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $data Request data
     * @param string|null $token Optional access token
     * @return array
     */
    public function callExternalApi($provider, $endpoint, $method = 'GET', $data = [], $token = null)
    {
        try {
            $baseUrl = config("services.{$provider}.base_url");
            
            if (!$baseUrl) {
                throw new \Exception("Base URL not configured for provider: {$provider}");
            }

            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

            $headers = [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            if ($token) {
                $headers['Authorization'] = "Bearer {$token}";
            }

            $response = Http::withHeaders($headers)
                ->withOptions(['verify' => false]) // For development only
                ->{strtolower($method)}($url, $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status' => $response->status(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];

        } catch (\Exception $e) {
            Log::error('External API call failed', [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh an OAuth token for a provider
     *
     * @param string $provider
     * @param string $refreshToken
     * @return array
     */
    public function refreshToken($provider, $refreshToken)
    {
        try {
            $tokenUrl = config("services.{$provider}.token_url");
            $clientId = config("services.{$provider}.client_id");
            $clientSecret = config("services.{$provider}.client_secret");

            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to refresh token',
            ];

        } catch (\Exception $e) {
            Log::error('Token refresh failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
