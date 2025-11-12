<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimpleMatchController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Simple web interface for testing the matching function
Route::get('/', function () {
    return response()->json([
        'message' => 'Laravel Matching API is running!',
        'endpoints' => [
            'POST /match' => 'Test matching function with tracking and video data',
            'GET /match/test' => 'Test with sample data',
            'GET /health' => 'Health check',
            'GET /api/v1/matches' => 'Get all matches'
        ],
        'example_usage' => [
            'endpoint' => 'POST /match',
            'payload' => [
                'trackingData' => ['id' => 184, 'teamName' => 'Capelle 1', '...'],
                'videoData' => ['id' => 'video-123', 'club' => ['name' => 'VV Capelle'], '...']
            ]
        ]
    ]);
});

// Simple matching endpoint for direct testing (using controller)
Route::post('/match', [SimpleMatchController::class, 'performMatch']);

// Test endpoint with sample data
Route::get('/match/test', [SimpleMatchController::class, 'testMatch']);
