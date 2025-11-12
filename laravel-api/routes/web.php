<?php

use Illuminate\Support\Facades\Route;

// Simple welcome message
Route::get('/', function () {
    return response()->json([
        'message' => 'Laravel Matching API',
        'status' => 'running',
        'endpoints' => [
            'POST /api/match' => 'Match tracking and video data',
            'GET /api/test' => 'Test with sample data',
            'GET /api/health' => 'Health check'
        ]
    ]);
});
