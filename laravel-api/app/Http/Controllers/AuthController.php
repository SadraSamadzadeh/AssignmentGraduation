<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $externalAuthService;

    public function __construct(ExternalAuthService $externalAuthService)
    {
        $this->externalAuthService = $externalAuthService;
    }

    /**
     * Unified login endpoint - handles local, Primeplay, and Video Dashboard authentication
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string',
            'password' => 'required|string',
            'auth_system' => 'required|in:local,primeplay,video_dashboard'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $authSystem = $request->auth_system;
        $email = $request->email;
        $password = $request->password;

        try {
            switch ($authSystem) {
                case 'local':
                    return $this->loginLocal($email, $password);
                
                case 'primeplay':
                    return $this->loginPrimeplay($email, $password);
                
                case 'video_dashboard':
                    return $this->loginVideoDashboard($email, $password);
                
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid authentication system'
                    ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Login error', [
                'auth_system' => $authSystem,
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Local authentication
     */
    protected function loginLocal(string $email, string $password): JsonResponse
    {
        $user = User::where('email', $email)
            ->where('auth_system', 'local')
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('Local authentication successful', [
            'user_id' => $user->id,
            'email' => $email
        ]);

        return response()->json([
            'success' => true,
            'auth_system' => 'local',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer'
        ]);
    }

    /**
     * Primeplay external authentication
     */
    protected function loginPrimeplay(string $username, string $password): JsonResponse
    {
        $authResult = $this->externalAuthService->authenticatePrimeplay($username, $password);

        if (!$authResult || !$authResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Primeplay authentication failed'
            ], 401);
        }

        // Find or create local user record for this external user
        $externalUserId = $authResult['user']['id'] ?? $authResult['user']['user_id'] ?? null;
        $externalEmail = $authResult['user']['email'] ?? $username;
        $externalName = $authResult['user']['name'] ?? $authResult['user']['username'] ?? $username;

        $user = User::updateOrCreate(
            [
                'auth_system' => 'primeplay',
                'external_user_id' => $externalUserId
            ],
            [
                'name' => $externalName,
                'email' => $externalEmail,
                'external_credentials' => [
                    'token' => $authResult['token'],
                    'user_data' => $authResult['user']
                ],
                'external_token_expires_at' => $authResult['expires_at'],
                'last_login_at' => now()
            ]
        );

        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('Primeplay authentication successful', [
            'user_id' => $user->id,
            'external_user_id' => $externalUserId
        ]);

        return response()->json([
            'success' => true,
            'auth_system' => 'primeplay',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'external_token' => $authResult['token']
        ]);
    }

    /**
     * Video Dashboard external authentication
     */
    protected function loginVideoDashboard(string $email, string $password): JsonResponse
    {
        $authResult = $this->externalAuthService->authenticateVideoDashboard($email, $password);

        if (!$authResult || !$authResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Video Dashboard authentication failed'
            ], 401);
        }

        // Find or create local user record for this external user
        $externalUserId = $authResult['user']['id'] ?? $authResult['user']['user_id'] ?? null;
        $externalEmail = $authResult['user']['email'] ?? $email;
        $externalName = $authResult['user']['name'] ?? $authResult['user']['username'] ?? $email;

        $user = User::updateOrCreate(
            [
                'auth_system' => 'video_dashboard',
                'external_user_id' => $externalUserId
            ],
            [
                'name' => $externalName,
                'email' => $externalEmail,
                'external_credentials' => [
                    'token' => $authResult['token'],
                    'user_data' => $authResult['user']
                ],
                'external_token_expires_at' => $authResult['expires_at'],
                'last_login_at' => now()
            ]
        );

        $token = $user->createToken('auth_token')->plainTextToken;

        Log::info('Video Dashboard authentication successful', [
            'user_id' => $user->id,
            'external_user_id' => $externalUserId
        ]);

        return response()->json([
            'success' => true,
            'auth_system' => 'video_dashboard',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'external_token' => $authResult['token']
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user information
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $user
        ]);
    }

    /**
     * Make API call to external dashboard (Primeplay or Video Dashboard)
     */
    public function externalApiCall(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'system' => 'required|in:primeplay,video_dashboard',
            'endpoint' => 'required|string',
            'method' => 'nullable|in:GET,POST,PUT,DELETE',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        
        if ($user->auth_system !== $request->system) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated with this system'
            ], 403);
        }

        $credentials = $user->external_credentials;
        $token = $credentials['token'] ?? null;

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'No external token found'
            ], 401);
        }

        try {
            $result = $request->system === 'primeplay'
                ? $this->externalAuthService->callPrimeplayApi(
                    $token,
                    $request->endpoint,
                    $request->method ?? 'GET',
                    $request->data ?? []
                )
                : $this->externalAuthService->callVideoDashboardApi(
                    $token,
                    $request->endpoint,
                    $request->method ?? 'GET',
                    $request->data ?? []
                );

            if ($result === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'External API call failed'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('External API call error', [
                'system' => $request->system,
                'endpoint' => $request->endpoint,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'External API call failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
