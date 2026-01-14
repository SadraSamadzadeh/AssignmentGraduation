<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Register a new user with comprehensive validation and security measures
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Create user with hashed password (automatic via User model cast)
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')), // Bcrypt with cost factor 12
                'auth_system' => 'local', // Mark as local auth user
                'email_verified_at' => null, // Require email verification in production
            ]);

            // Hash sensitive data if storing external credentials
            if ($request->has('external_credentials')) {
                $user->external_credentials = $this->hashSensitiveData($request->external_credentials);
                $user->save();
            }

            DB::commit();

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Generate JWT token for immediate login
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('User registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Authenticate user and generate JWT token with rate limiting
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // Rate limiting check (5 attempts per email+IP combination)
            $request->authenticate();

            $credentials = $request->only('email', 'password');

            if (!$token = JWTAuth::attempt($credentials)) {
                // Hit rate limiter on failed attempt
                RateLimiter::hit($request->throttleKey(), 60);
                
                Log::warning('Failed login attempt', [
                    'email' => $request->email,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Clear rate limiter on successful login
            RateLimiter::clear($request->throttleKey());

            $user = auth()->user();
            
            // Update last login timestamp
            $user->update([
                'last_login_at' => Carbon::now(),
            ]);
            
            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $this->respondWithToken($token);

        } catch (JWTException $e) {
            Log::error('JWT token creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Could not create token'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Login process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during login'
            ], 500);
        }
    }

    /**
     * Get the authenticated user
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'auth_system' => $user->auth_system,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user information'
            ], 500);
        }
    }

    /**
     * Logout user (invalidate token)
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        try {
            $userId = auth()->user()->id ?? null;
            
            auth()->logout();
            
            Log::info('User logged out successfully', ['user_id' => $userId]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out'
            ]);

        } catch (\Exception $e) {
            Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * Refresh JWT token
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth()->refresh();
            
            Log::info('Token refreshed successfully', [
                'user_id' => auth()->user()->id,
            ]);

            return $this->respondWithToken($newToken);

        } catch (JWTException $e) {
            Log::error('Token refresh failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Could not refresh token'
            ], 500);
        }
    }

    /**
     * Return token response structure
     *
     * @param string $token
     * @return JsonResponse
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => [
                'id' => auth()->user()->id,
                'name' => auth()->user()->name,
                'email' => auth()->user()->email,
                'auth_system' => auth()->user()->auth_system,
            ]
        ]);
    }

    /**
     * Hash sensitive data before storage using secure encryption
     *
     * @param mixed $data
     * @return string
     */
    protected function hashSensitiveData($data): string
    {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        
        // Use secure encryption for retrievable sensitive data
        return encrypt($data);
    }

    /**
     * Decrypt sensitive hashed data
     *
     * @param string $hashedData
     * @return mixed
     */
    protected function decryptSensitiveData(string $hashedData)
    {
        try {
            return decrypt($hashedData);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt sensitive data', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
