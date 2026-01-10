<?php

namespace App\Http\Controllers;

use App\Models\ExternalApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ExternalApiController extends Controller
{
    private const BASE_URL = 'https://localhost:7289/api';
    private const PROVIDER = 'usf_api';

    /**
     * Login to external API and store the tokens
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = Http::withOptions(['verify' => false])
                ->post(self::BASE_URL . '/account/login', [
                    'email' => $request->email,
                    'password' => $request->password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Store tokens in database
                if (isset($data['accessToken'])) {
                    // Delete old tokens for this provider
                    ExternalApiToken::where('provider', self::PROVIDER)->delete();
                    
                    // Store new tokens
                    ExternalApiToken::create([
                        'provider' => self::PROVIDER,
                        'access_token' => $data['accessToken'],
                        'refresh_token' => $data['refreshToken'] ?? null,
                        'expires_at' => isset($data['expiresIn']) 
                            ? now()->addSeconds($data['expiresIn']) 
                            : now()->addHours(24),
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Login successful, tokens stored',
                        'data' => $data
                    ], 200);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'No accessToken found in response',
                    'data' => $data
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => $response->body()
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error connecting to external API',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refreshAccessToken()
    {
        $tokenRecord = ExternalApiToken::where('provider', self::PROVIDER)->first();
        
        if (!$tokenRecord || !$tokenRecord->refresh_token) {
            return false;
        }

        try {
            $response = Http::withOptions(['verify' => false])
                ->post(self::BASE_URL . '/account/refresh', [
                    'refreshToken' => $tokenRecord->refresh_token,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['accessToken'])) {
                    $tokenRecord->update([
                        'access_token' => $data['accessToken'],
                        'refresh_token' => $data['refreshToken'] ?? $tokenRecord->refresh_token,
                        'expires_at' => isset($data['expiresIn']) 
                            ? now()->addSeconds($data['expiresIn']) 
                            : now()->addHours(24),
                    ]);
                    
                    return $data['accessToken'];
                }
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get valid access token (refresh if needed)
     */
    private function getValidAccessToken()
    {
        $tokenRecord = ExternalApiToken::where('provider', self::PROVIDER)->first();
        
        if (!$tokenRecord) {
            return null;
        }

        // If token is expired or about to expire in 5 minutes, refresh it
        if ($tokenRecord->isExpired() || now()->addMinutes(5)->isAfter($tokenRecord->expires_at)) {
            $newToken = $this->refreshAccessToken();
            return $newToken ?: null;
        }

        return $tokenRecord->access_token;
    }

    /**
     * Get dataset data from external API using stored token
     */
    public function getDataset($datasetId)
    {
        // Get valid access token
        $token = $this->getValidAccessToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'No valid authentication token found. Please login first.',
            ], 401);
        }

        try {
            $response = Http::withOptions(['verify' => false])
                ->withToken($token)
                ->get(self::BASE_URL . '/datasets/' . $datasetId);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Dataset retrieved successfully',
                    'data' => $response->json()
                ], 200);
            }

            // If unauthorized, try to refresh token once
            if ($response->status() === 401) {
                $newToken = $this->refreshAccessToken();
                
                if ($newToken) {
                    // Retry the request with new token
                    $response = Http::withOptions(['verify' => false])
                        ->withToken($newToken)
                        ->get(self::BASE_URL . '/datasets/' . $datasetId);
                    
                    if ($response->successful()) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Dataset retrieved successfully',
                            'data' => $response->json()
                        ], 200);
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Token expired or invalid. Please login again.',
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dataset',
                'error' => $response->body()
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error connecting to external API',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
