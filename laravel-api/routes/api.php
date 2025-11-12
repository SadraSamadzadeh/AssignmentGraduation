<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', [HealthController::class, 'check']);

// API v1 routes
Route::prefix('api/v1')->group(function () {
    // Match management
    Route::get('/matches', [MatchController::class, 'index']);
    Route::get('/matches/{globalId}', [MatchController::class, 'show']);
    Route::post('/matches', [MatchController::class, 'store']);
    Route::post('/matches/batch', [MatchController::class, 'batchProcess']);
    Route::delete('/matches/{globalId}', [MatchController::class, 'destroy']);
    
    // Statistics
    Route::get('/statistics', [MatchController::class, 'statistics']);
});

// Fallback route for API
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'available_endpoints' => [
            'GET /health' => 'Server health check',
            'GET /api/v1/matches' => 'Get all matches',
            'GET /api/v1/matches/{id}' => 'Get specific match',
            'POST /api/v1/matches' => 'Create single match',
            'POST /api/v1/matches/batch' => 'Batch process matches',
            'DELETE /api/v1/matches/{id}' => 'Delete match',
            'GET /api/v1/statistics' => 'Get match statistics'
        ]
    ], 404);
});