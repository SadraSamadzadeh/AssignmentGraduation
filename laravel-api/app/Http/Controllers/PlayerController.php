<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\TrackingDashboard;
use App\Models\GlobalMatches;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PlayerController extends Controller
{
    /**
     * Get all players for a specific tracking event
     * 
     * @param int $trackingId
     * @return JsonResponse
     */
    public function getPlayersByTracking(int $trackingId): JsonResponse
    {
        try {
            $tracking = TrackingDashboard::where('tracking_id', $trackingId)->first();
            
            if (!$tracking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tracking event not found'
                ], 404);
            }

            $players = $tracking->players()
                ->orderBy('jersey_number')
                ->get();

            return response()->json([
                'success' => true,
                'tracking_id' => $trackingId,
                'dataset_id' => $tracking->dataset_name,
                'team_name' => $tracking->team_name,
                'event_date' => $tracking->event_date,
                'players_count' => $players->count(),
                'players' => $players
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve players for tracking', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve players'
            ], 500);
        }
    }

    /**
     * Get player by device ID
     * 
     * @param string $deviceId
     * @return JsonResponse
     */
    public function getPlayerByDevice(string $deviceId): JsonResponse
    {
        try {
            $player = Player::where('device_id', $deviceId)
                ->with('trackingDashboard')
                ->first();

            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'player' => $player
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve player by device', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve player'
            ], 500);
        }
    }

    /**
     * Get all players for a specific dataset
     * 
     * @param string $datasetId
     * @return JsonResponse
     */
    public function getPlayersByDataset(string $datasetId): JsonResponse
    {
        try {
            $players = Player::where('dataset_id', $datasetId)
                ->with('trackingDashboard')
                ->orderBy('jersey_number')
                ->get();

            if ($players->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No players found for this dataset'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'dataset_id' => $datasetId,
                'players_count' => $players->count(),
                'players' => $players
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve players for dataset', [
                'dataset_id' => $datasetId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve players'
            ], 500);
        }
    }

    /**
     * Search players by name or team
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchPlayers(Request $request): JsonResponse
    {
        try {
            $query = Player::query()->with('trackingDashboard');

            if ($request->has('name')) {
                $query->where('player_name', 'ILIKE', '%' . $request->name . '%');
            }

            if ($request->has('team')) {
                $query->where('team_name', 'ILIKE', '%' . $request->team . '%');
            }

            if ($request->has('jersey_number')) {
                $query->where('jersey_number', $request->jersey_number);
            }

            $players = $query->orderBy('player_name')->get();

            return response()->json([
                'success' => true,
                'players_count' => $players->count(),
                'players' => $players
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to search players', [
                'search_params' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to search players'
            ], 500);
        }
    }

    /**
     * Get all players with their latest tracking events
     * 
     * @return JsonResponse
     */
    public function getAllPlayers(): JsonResponse
    {
        try {
            $players = Player::with('trackingDashboard')
                ->orderBy('last_seen_at', 'desc')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'players' => $players
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve all players', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve players'
            ], 500);
        }
    }

    /**
     * Get player data from Primeplay (matched) and Video Dashboard by device ID and date
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPlayerMatchedData(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => 'required|string',
            'date' => 'required|date'
        ]);

        try {
            $deviceId = $request->device_id;
            $date = $request->date;

            // Find player by device ID
            $player = Player::where('device_id', $deviceId)->first();

            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player not found with this device ID'
                ], 404);
            }

            // Get dataset ID from player
            $datasetId = $player->dataset_id;

            if (!$datasetId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No dataset ID associated with this player'
                ], 404);
            }

            // Find tracking dashboard with matched status for this dataset and date
            $trackingDashboard = TrackingDashboard::where('tracking_id', $datasetId)
                ->where('status', 'matched')
                ->whereDate('event_date', $date)
                ->first();

            if (!$trackingDashboard) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matched tracking data found for this dataset and date'
                ], 404);
            }

            // Find the global match that links tracking to video
            $globalMatch = GlobalMatches::where('tracking_id', $datasetId)
                ->whereDate('matched_at', $date)
                ->first();

            if (!$globalMatch) {
                return response()->json([
                    'success' => false,
                    'message' => 'No global match found for this tracking data'
                ], 404);
            }

            // Get all players for this tracking event
            $allPlayers = $trackingDashboard->players()
                ->orderBy('jersey_number')
                ->get();

            // Prepare response with both Primeplay (tracking) and Video data
            return response()->json([
                'success' => true,
                'player' => [
                    'id' => $player->id,
                    'name' => $player->player_name,
                    'device_id' => $player->device_id,
                    'jersey_number' => $player->jersey_number,
                    'position' => $player->position,
                    'team_name' => $player->team_name,
                ],
                'dataset_id' => $datasetId,
                'event_date' => $date,
                'primeplay_data' => [
                    'tracking_id' => $trackingDashboard->tracking_id,
                    'source_system' => $trackingDashboard->source_system,
                    'status' => $trackingDashboard->status,
                    'team_name' => $trackingDashboard->team_name,
                    'dataset_name' => $trackingDashboard->dataset_name,
                    'start_time' => $trackingDashboard->start_time,
                    'end_time' => $trackingDashboard->end_time,
                    'duration_minutes' => $trackingDashboard->duration_minutes,
                    'event_date' => $trackingDashboard->event_date,
                    'tracking_data' => $trackingDashboard->tracking_data,
                    'all_players' => $allPlayers,
                ],
                'video_data' => [
                    'video_id' => $globalMatch->video_id,
                    'video_details' => $globalMatch->video_data,
                ],
                'match_info' => [
                    'global_match_id' => $globalMatch->global_match_id,
                    'match_score' => $globalMatch->match_score,
                    'confidence_level' => $globalMatch->confidence_level,
                    'status' => $globalMatch->status,
                    'matched_at' => $globalMatch->matched_at,
                    'time_proximity_score' => $globalMatch->time_proximity_score,
                    'duration_similarity_score' => $globalMatch->duration_similarity_score,
                    'temporal_overlap_score' => $globalMatch->temporal_overlap_score,
                    'match_details' => $globalMatch->match_details,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve player matched data', [
                'device_id' => $request->device_id,
                'date' => $request->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve player matched data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
