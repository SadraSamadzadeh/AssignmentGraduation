<?php

namespace App\Services;

use App\Models\User;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ConnectedAccountService
{
    protected $externalAuthService;

    public function __construct(ExternalAuthService $externalAuthService)
    {
        $this->externalAuthService = $externalAuthService;
    }

    /**
     * Connect or update a provider account for the user.
     * Signature aligns with unit tests expectations.
     */
    public function connectAccount(
        User $user,
        string $provider,
        ?string $accessToken,
        ?string $refreshToken,
        int $expiresIn,
        ?string $providerUserId,
        string $providerUsername,
        bool $setPrimary = false
    )
    {
        try {
            // Find existing account
            $existing = ConnectedAccount::where('user_id', $user->id)
                ->where('provider', $provider)
                ->first();

            DB::beginTransaction();

            // If this is the first connected account or set as primary, make it primary
            $isFirstAccount = ConnectedAccount::where('user_id', $user->id)->count() === 0;
            $isPrimary = $setPrimary || $isFirstAccount;

            // If setting as primary, remove primary flag from other accounts
            if ($isPrimary) {
                ConnectedAccount::where('user_id', $user->id)
                    ->update(['is_primary' => false]);
            }

            if ($existing && $existing->status !== 'active') {
                // Reactivate and update existing inactive account
                $existing->update([
                    'provider_user_id' => $providerUserId,
                    'provider_username' => $providerUsername,
                    'access_token' => $accessToken ? encrypt($accessToken) : null,
                    'refresh_token' => $refreshToken ? encrypt($refreshToken) : null,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'status' => 'active',
                    'last_synced_at' => now(),
                ]);
                $connectedAccount = $existing;
            } elseif ($existing && $existing->status === 'active') {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => ucfirst($provider) . ' account is already connected'
                ];
            } else {
                // Create connected account
                $connectedAccount = ConnectedAccount::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_user_id' => $providerUserId,
                    'provider_username' => $providerUsername,
                    'provider_email' => null,
                    'access_token' => $accessToken ? encrypt($accessToken) : null,
                    'refresh_token' => $refreshToken ? encrypt($refreshToken) : null,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'is_primary' => $isPrimary,
                    'status' => 'active',
                    'last_synced_at' => now(),
                    'metadata' => [
                        'connected_at' => now()->toIso8601String(),
                        'provider_name' => $providerUsername,
                    ]
                ]);
            }

            DB::commit();

            Log::info('Account connected successfully', [
                'user_id' => $user->id,
                'provider' => $provider,
                'is_primary' => $isPrimary
            ]);

            return [
                'success' => true,
                'message' => ucfirst($provider) . ' account connected successfully',
                'connected_account' => $connectedAccount->makeHidden(['access_token', 'refresh_token'])
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to connect account', [
                'user_id' => $user->id,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to connect account: ' . $e->getMessage()
            ];
        }
    }

    public function disconnectAccount(User $user, string $provider)
    {
        try {
            $connectedAccount = ConnectedAccount::where('user_id', $user->id)
                ->where('provider', $provider)
                ->first();

            if (!$connectedAccount) {
                return [
                    'success' => false,
                    'message' => ucfirst($provider) . ' account not found'
                ];
            }

            // Check if this is the last account
            $accountCount = ConnectedAccount::where('user_id', $user->id)
                ->where('status', 'active')
                ->count();

            if ($accountCount === 1) {
                return [
                    'success' => false,
                    'message' => 'Cannot disconnect your only connected account. Connect another account first.'
                ];
            }

            DB::beginTransaction();

            // Delete the connected account (aligns with unit test expectations)
            $wasPrimary = $connectedAccount->is_primary;
            $connectedAccount->delete();

            // If this was primary, set another account as primary
            if ($wasPrimary) {
                $newPrimary = ConnectedAccount::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->first();

                if ($newPrimary) {
                    $newPrimary->update(['is_primary' => true]);
                }
            }

            DB::commit();

            Log::info('Account disconnected successfully', [
                'user_id' => $user->id,
                'provider' => $provider
            ]);

            return [
                'success' => true,
                'message' => ucfirst($provider) . ' account disconnected successfully'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to disconnect account', [
                'user_id' => $user->id,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to disconnect account: ' . $e->getMessage()
            ];
        }
    }

    public function setPrimaryAccount(User $user, string $provider)
    {
        try {
            $connectedAccount = ConnectedAccount::where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('status', 'active')
                ->first();

            if (!$connectedAccount) {
                return [
                    'success' => false,
                    'message' => ucfirst($provider) . ' account is not connected or inactive'
                ];
            }

            if ($connectedAccount->is_primary) {
                return [
                    'success' => false,
                    'message' => ucfirst($provider) . ' account is already your primary account'
                ];
            }

            DB::beginTransaction();

            // Remove primary flag from all accounts
            ConnectedAccount::where('user_id', $user->id)
                ->update(['is_primary' => false]);

            // Set this account as primary
            $connectedAccount->update(['is_primary' => true]);

            DB::commit();

            Log::info('Primary account changed', [
                'user_id' => $user->id,
                'provider' => $provider
            ]);

            return [
                'success' => true,
                'message' => ucfirst($provider) . ' account set as primary'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to set primary account', [
                'user_id' => $user->id,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to set primary account: ' . $e->getMessage()
            ];
        }
    }

    public function getConnectedAccounts(User $user)
    {
        $accounts = ConnectedAccount::where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'provider' => $account->provider,
                    'provider_username' => $account->provider_username,
                    'provider_email' => $account->provider_email,
                    'is_primary' => $account->is_primary,
                    'status' => $account->status,
                    'connected_at' => $account->created_at,
                    'last_synced_at' => $account->last_synced_at,
                    'token_expires_at' => $account->token_expires_at,
                    'is_token_expired' => $account->isTokenExpired()
                ];
            })->all();

        return [
            'success' => true,
            'accounts' => $accounts
        ];
    }

    public function refreshToken(User $user, string $provider)
    {
        try {
            $connectedAccount = ConnectedAccount::where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('status', 'active')
                ->first();

            if (!$connectedAccount) {
                return [
                    'success' => false,
                    'message' => ucfirst($provider) . ' account is not connected'
                ];
            }

            // Re-authenticate with the provider
            $username = $connectedAccount->provider_username;
            $metadata = $connectedAccount->metadata;
            
            // Note: In production, you'd use refresh token or stored credentials
            // For now, we'll mark it as needing re-authentication
            if ($connectedAccount->isTokenExpired()) {
                $connectedAccount->update([
                    'status' => 'error',
                    'metadata' => array_merge($metadata ?? [], [
                        'error' => 'Token expired, please reconnect account',
                        'error_at' => now()->toIso8601String()
                    ])
                ]);

                return [
                    'success' => false,
                    'message' => 'Token expired. Please reconnect your ' . ucfirst($provider) . ' account.',
                    'requires_reconnect' => true
                ];
            }

            return [
                'success' => true,
                'message' => 'Token is still valid'
            ];

        } catch (\Exception $e) {
            Log::error('Failed to refresh account token', [
                'user_id' => $user->id,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to refresh token: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get token for API call
     */
    public function getProviderToken(User $user, string $provider)
    {
        $connectedAccount = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('status', 'active')
            ->first();

        if (!$connectedAccount) {
            throw new \Exception('No connected account found');
        }

        if ($connectedAccount->isTokenExpired()) {
            // Try to refresh
            $result = $this->refreshAccountToken($user, $provider);
            if (!$result['success']) {
                throw new \Exception('Token expired. Please reconnect your account.');
            }
        }

        return decrypt($connectedAccount->access_token);
    }

    /**
     * Make API call using connected account
     */
    public function makeApiCall(User $user, string $provider, string $endpoint, string $method = 'GET', array $data = [])
    {
        try {
            $token = $this->getProviderToken($user, $provider);

            // Update last synced time
            ConnectedAccount::where('user_id', $user->id)
                ->where('provider', $provider)
                ->update(['last_synced_at' => now()]);

            // Simple HTTP call structure based on provider
            $baseUrl = "https://{$provider}.example.com/api";
            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->{$method === 'POST' ? 'post' : 'get'}($url, $data);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('API call failed', [
                'user_id' => $user->id,
                'provider' => $provider,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function getAvailableProviders()
    {
        return [
            'success' => true,
            'providers' => [
                [
                    'id' => 'primeplay',
                    'name' => 'Primeplay',
                    'description' => 'Connect your Primeplay account to access tracking data, datasets, and player information',
                    'icon' => 'primeplay-icon.png',
                    'features' => [
                        'Access tracking data',
                        'View datasets and recordings',
                        'Player and device information',
                        'Match scheduling'
                    ]
                ],
                [
                    'id' => 'video_dashboard',
                    'name' => 'Video Dashboard',
                    'description' => 'Connect your Video Dashboard account to access video recordings and match analysis',
                    'icon' => 'video-dashboard-icon.png',
                    'features' => [
                        'Access video recordings',
                        'Match video analysis',
                        'Video metadata',
                        'Recording management'
                    ]
                ]
            ]
        ];
    }

    public function getConnectionStatus(User $user)
    {
        $providers = $this->getAvailableProviders()['providers'];
        $accounts = ConnectedAccount::where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->keyBy('provider');

        $connections = collect($providers)->map(function ($provider) use ($accounts) {
            $account = $accounts->get($provider['id']);
            return [
                'provider' => $provider['id'],
                'connected' => $account !== null,
                'is_primary' => $account ? (bool)$account->is_primary : false,
                'details' => $account ? [
                    'username' => $account->provider_username,
                    'connected_at' => $account->created_at,
                    'status' => $account->status,
                ] : null,
            ];
        })->all();

        return [
            'success' => true,
            'connections' => $connections,
        ];
    }
}
