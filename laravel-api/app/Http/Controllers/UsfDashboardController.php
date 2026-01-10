<?php

namespace App\Http\Controllers;

use App\Services\ConnectedAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UsfDashboardController extends Controller
{
    protected ConnectedAccountService $connectedAccountService;

    public function __construct(ConnectedAccountService $connectedAccountService)
    {
        $this->connectedAccountService = $connectedAccountService;
    }

    public function getRecordingsByClub(Request $request, string $club_id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'system' => 'nullable|in:primeplay,video_dashboard',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $clubId = $club_id;
            $system = $request->query('system', 'primeplay');
            $user = $request->user();

            Log::info("Fetching recordings for club", [
                'club_id' => $clubId,
                'system' => $system
            ]);

            if ($system === 'primeplay') {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'primeplay',
                    "/clubs/{$clubId}/recordings"
                );
            } else {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'video_dashboard',
                    "/clubs/{$clubId}/recordings"
                );
            }

            return response()->json([
                'success' => true,
                'club_id' => $clubId,
                'system' => $system,
                'recordings' => $response['data'] ?? [],
                'total' => count($response['data'] ?? [])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch recordings', [
                'club_id' => $request->input('club_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching recordings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getScheduledMatchesByClub(Request $request, string $club_id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'system' => 'nullable|in:primeplay,video_dashboard',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $clubId = $club_id;
            $system = $request->query('system', 'video_dashboard');
            $fromDate = $request->query('from_date');
            $toDate = $request->query('to_date');
            $user = $request->user();

            Log::info("Fetching scheduled matches for club", [
                'club_id' => $clubId,
                'system' => $system,
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            $queryParams = [];
            if ($fromDate) {
                $queryParams['from_date'] = $fromDate;
            }
            if ($toDate) {
                $queryParams['to_date'] = $toDate;
            }

            $endpoint = "/clubs/{$clubId}/matches/scheduled";
            if (!empty($queryParams)) {
                $endpoint .= '?' . http_build_query($queryParams);
            }

            if ($system === 'primeplay') {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'primeplay',
                    $endpoint
                );
            } else {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'video_dashboard',
                    $endpoint
                );
            }

            return response()->json([
                'success' => true,
                'club_id' => $clubId,
                'system' => $system,
                'matches' => $response['data'] ?? [],
                'total' => count($response['data'] ?? []),
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch scheduled matches', [
                'club_id' => $clubId,
                'system' => $system,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch scheduled matches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getClubInfo(Request $request, string $club_id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'system' => 'nullable|in:primeplay,video_dashboard',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $clubId = $club_id;
            $system = $request->query('system', 'primeplay');
            $user = $request->user();

            Log::info("Fetching club information", [
                'club_id' => $clubId,
                'system' => $system
            ]);

            if ($system === 'primeplay') {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'primeplay',
                    "/clubs/{$clubId}"
                );
            } else {
                $response = $this->connectedAccountService->makeApiCall(
                    $user,
                    'video_dashboard',
                    "/clubs/{$clubId}"
                );
            }

            return response()->json([
                'success' => true,
                'club_id' => $clubId,
                'system' => $system,
                'club' => $response['data'] ?? []
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch club information', [
                'club_id' => $request->input('club_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching club information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all data for a club (recordings, matches, and info)
     * 
     * @param Request $request
     * @param string $club_id
     * @return JsonResponse
     */
    public function getClubAllData(Request $request, string $club_id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'system' => 'nullable|in:primeplay,video_dashboard',
            'include' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $clubId = $club_id;
            $system = $request->query('system', 'primeplay');
            $includeParam = $request->query('include', 'recordings,matches,info');
            $include = explode(',', $includeParam);

            Log::info("Fetching all club data", [
                'club_id' => $clubId,
                'system' => $system,
                'include' => $include
            ]);

            $result = [
                'success' => true,
                'club_id' => $clubId,
                'system' => $system,
            ];

            // Fetch recordings
            if (in_array('recordings', $include)) {
                $recordingsResponse = $this->getRecordingsByClub($request, $clubId);
                $recordingsData = json_decode($recordingsResponse->getContent(), true);
                $result['recordings'] = $recordingsData['recordings'] ?? [];
            }

            // Fetch scheduled matches
            if (in_array('matches', $include)) {
                $matchesResponse = $this->getScheduledMatchesByClub($request, $clubId);
                $matchesData = json_decode($matchesResponse->getContent(), true);
                $result['scheduled_matches'] = $matchesData['matches'] ?? [];
            }

            // Fetch club info
            if (in_array('info', $include)) {
                $clubInfoResponse = $this->getClubInfo($request, $clubId);
                $clubInfoData = json_decode($clubInfoResponse->getContent(), true);
                $result['club_info'] = $clubInfoData['club'] ?? [];
            }

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Failed to fetch all club data', [
                'club_id' => $request->input('club_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching club data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dataset data by dataset ID from Primeplay
     * 
     * @param Request $request
     * @param string $dataset_id
    public function getDatasetData(Request $request, string $dataset_id): JsonResponse
    {
        try {
            $datasetId = $dataset_id;
            $user = $request->user();

            Log::info("Fetching dataset data from Primeplay", [
                'dataset_id' => $datasetId
            ]);

            $response = $this->connectedAccountService->makeApiCall(
                $user,
                'primeplay',
                "/datasets/{$datasetId}"
            );

            return response()->json([
                'success' => true,
                'dataset_id' => $datasetId,
                'system' => 'primeplay',
                'dataset' => $response['data'] ?? []
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch dataset data', [
                'dataset_id' => $request->input('dataset_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching dataset data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get players and device IDs by dataset ID from Primeplay
    public function getPlayersByDatasetId(Request $request, string $dataset_id): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'include_devices' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $datasetId = $dataset_id;
            $includeDevices = $request->query('include_devices', true);
            $user = $request->user();

            Log::info("Fetching players for dataset from Primeplay", [
                'dataset_id' => $datasetId,
                'include_devices' => $includeDevices
            ]);

            $endpoint = "/datasets/{$datasetId}/players";
            if ($includeDevices) {
                $endpoint .= '?include_devices=true';
            }

            $response = $this->connectedAccountService->makeApiCall(
                $user,
                'primeplay',
                $endpoint
            );

            $players = $response['data'] ?? [];
            
            $deviceIds = [];
            if ($includeDevices && is_array($players)) {
                foreach ($players as $player) {
                    if (isset($player['device_id']) && !empty($player['device_id'])) {
                        $deviceIds[] = $player['device_id'];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'dataset_id' => $datasetId,
                'system' => 'primeplay',
                'players' => $players,
                'device_ids' => array_unique($deviceIds),
                'total_players' => count($players),
                'total_devices' => count(array_unique($deviceIds))
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch players for dataset', [
                'dataset_id' => $request->input('dataset_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching players',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get device IDs only by dataset ID from Primeplay
     * 
     * @param Request $request
     * @param string $dataset_id
     * @return JsonResponse
     */
    public function getDeviceIdsByDatasetId(Request $request, string $dataset_id): JsonResponse
    {
        try {
            $datasetId = $dataset_id;
            $user = $request->user();

            Log::info("Fetching device IDs for dataset from Primeplay", [
                'dataset_id' => $datasetId
            ]);

            $response = $this->connectedAccountService->makeApiCall(
                $user,
                'primeplay',
                "/datasets/{$datasetId}/devices"
            );

            $devices = $response['data'] ?? [];
            $deviceIds = [];

            if (is_array($devices)) {
                foreach ($devices as $device) {
                    if (isset($device['device_id'])) {
                        $deviceIds[] = $device['device_id'];
                    } elseif (isset($device['id'])) {
                        $deviceIds[] = $device['id'];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'dataset_id' => $datasetId,
                'system' => 'primeplay',
                'device_ids' => array_unique($deviceIds),
                'devices' => $devices,
                'total' => count($devices)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch device IDs for dataset', [
                'dataset_id' => $request->input('dataset_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error while fetching device IDs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
