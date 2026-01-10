<?php

namespace App\Http\Controllers;

use App\Services\ConnectedAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConnectedAccountController extends Controller
{
    protected $connectedAccountService;

    public function __construct(ConnectedAccountService $connectedAccountService)
    {
        $this->connectedAccountService = $connectedAccountService;
    }

    /**
     * Get all connected accounts for authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connectedAccounts = $this->connectedAccountService->getConnectedAccounts($user);

        return response()->json([
            'success' => true,
            'connected_accounts' => $connectedAccounts
        ]);
    }

    /**
     * Get available providers and connection status
     */
    public function getProviders(Request $request)
    {
        $user = $request->user();
        $providers = $this->connectedAccountService->getConnectionStatus($user);

        return response()->json([
            'success' => true,
            'providers' => $providers
        ]);
    }

    public function connect(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|in:primeplay,video_dashboard',
            'username' => 'required|string',
            'password' => 'required|string',
            'set_as_primary' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $result = $this->connectedAccountService->connectAccount(
            $user,
            $request->provider,
            $request->username,
            $request->password,
            $request->set_as_primary ?? false
        );

        $statusCode = $result['success'] ? 200 : 400;
        return response()->json($result, $statusCode);
    }

    /**
     * Disconnect an account
     */
    public function disconnect(Request $request, string $provider)
    {
        if (!in_array($provider, ['primeplay', 'video_dashboard'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider'
            ], 422);
        }

        $user = $request->user();
        $result = $this->connectedAccountService->disconnectAccount($user, $provider);

        $statusCode = $result['success'] ? 200 : 400;
        return response()->json($result, $statusCode);
    }

    /**
     * Set an account as primary
     */
    public function setPrimary(Request $request, string $provider)
    {
        if (!in_array($provider, ['primeplay', 'video_dashboard'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider'
            ], 422);
        }

        $user = $request->user();
        $result = $this->connectedAccountService->setPrimaryAccount($user, $provider);

        $statusCode = $result['success'] ? 200 : 400;
        return response()->json($result, $statusCode);
    }

    /**
     * Refresh account token
     */
    public function refreshToken(Request $request, string $provider)
    {
        if (!in_array($provider, ['primeplay', 'video_dashboard'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider'
            ], 422);
        }

        $user = $request->user();
        $result = $this->connectedAccountService->refreshAccountToken($user, $provider);

        $statusCode = $result['success'] ? 200 : 400;
        return response()->json($result, $statusCode);
    }

    /**
     * Test connection to a provider
     */
    public function testConnection(Request $request, string $provider)
    {
        if (!in_array($provider, ['primeplay', 'video_dashboard'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider'
            ], 422);
        }

        $user = $request->user();

        try {
            // Try to make a simple API call to test the connection
            $endpoint = $provider === 'primeplay' ? '/clubs' : '/recordings';
            $this->connectedAccountService->makeApiCall($user, $provider, $endpoint, 'GET');

            return response()->json([
                'success' => true,
                'message' => 'Connection is active and working'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 400);
        }
    }
}
