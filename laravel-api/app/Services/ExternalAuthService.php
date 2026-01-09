<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ExternalAuthService
{
    protected $primeplayBaseUrl;
    protected $videoDashboardBaseUrl;

    public function __construct()
    {
        $this->primeplayBaseUrl = env('PRIMEPLAY_API_URL', 'https://api.primeplay.com');
        $this->videoDashboardBaseUrl = env('VIDEO_DASHBOARD_API_URL', 'https://api.videodashboard.com');
    }

    /**
     * Authenticate user with Primeplay dashboard
     * 
     * @param string $username
     * @param string $password
     * @return array|null
     */
    public function authenticatePrimeplay(string $username, string $password): ?array
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->primeplayBaseUrl}/auth/login", [
                    'username' => $username,
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Primeplay authentication successful', [
                    'username' => $username,
                    'user_id' => $data['user']['id'] ?? null
                ]);

                return [
                    'success' => true,
                    'token' => $data['token'] ?? $data['access_token'] ?? null,
                    'user' => $data['user'] ?? [],
                    'expires_at' => $data['expires_at'] ?? now()->addHours(24),
                ];
            }

            Log::warning('Primeplay authentication failed', [
                'username' => $username,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Primeplay authentication error', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Authenticate user with Video Dashboard
     * 
     * @param string $email
     * @param string $password
     * @return array|null
     */
    public function authenticateVideoDashboard(string $email, string $password): ?array
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->videoDashboardBaseUrl}/api/auth/login", [
                    'email' => $email,
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Video Dashboard authentication successful', [
                    'email' => $email,
                    'user_id' => $data['user']['id'] ?? null
                ]);

                return [
                    'success' => true,
                    'token' => $data['token'] ?? $data['access_token'] ?? null,
                    'user' => $data['user'] ?? [],
                    'expires_at' => $data['expires_at'] ?? now()->addHours(24),
                ];
            }

            Log::warning('Video Dashboard authentication failed', [
                'email' => $email,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Video Dashboard authentication error', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Make authenticated API call to Primeplay
     * 
     * @param string $token
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array|null
     */
    public function callPrimeplayApi(string $token, string $endpoint, string $method = 'GET', array $data = []): ?array
    {
        try {
            $request = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json']);

            $response = match(strtoupper($method)) {
                'GET' => $request->get("{$this->primeplayBaseUrl}{$endpoint}", $data),
                'POST' => $request->post("{$this->primeplayBaseUrl}{$endpoint}", $data),
                'PUT' => $request->put("{$this->primeplayBaseUrl}{$endpoint}", $data),
                'DELETE' => $request->delete("{$this->primeplayBaseUrl}{$endpoint}", $data),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
            };

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Primeplay API call failed', [
                'endpoint' => $endpoint,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Primeplay API call error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Make authenticated API call to Video Dashboard
     * 
     * @param string $token
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array|null
     */
    public function callVideoDashboardApi(string $token, string $endpoint, string $method = 'GET', array $data = []): ?array
    {
        try {
            $request = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json']);

            $response = match(strtoupper($method)) {
                'GET' => $request->get("{$this->videoDashboardBaseUrl}{$endpoint}", $data),
                'POST' => $request->post("{$this->videoDashboardBaseUrl}{$endpoint}", $data),
                'PUT' => $request->put("{$this->videoDashboardBaseUrl}{$endpoint}", $data),
                'DELETE' => $request->delete("{$this->videoDashboardBaseUrl}{$endpoint}", $data),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
            };

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Video Dashboard API call failed', [
                'endpoint' => $endpoint,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Video Dashboard API call error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate external token
     * 
     * @param string $system
     * @param string $token
     * @return bool
     */
    public function validateExternalToken(string $system, string $token): bool
    {
        $cacheKey = "external_token_valid:{$system}:{$token}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $endpoint = $system === 'primeplay' ? '/auth/validate' : '/api/auth/validate';
            $baseUrl = $system === 'primeplay' ? $this->primeplayBaseUrl : $this->videoDashboardBaseUrl;

            $response = Http::timeout(10)
                ->withToken($token)
                ->get("{$baseUrl}{$endpoint}");

            $isValid = $response->successful();
            
            Cache::put($cacheKey, $isValid, now()->addMinutes(5));
            
            return $isValid;

        } catch (\Exception $e) {
            Log::error('External token validation error', [
                'system' => $system,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
