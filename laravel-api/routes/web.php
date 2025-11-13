<?php

use Illuminate\Support\Facades\Route;

// Root endpoint - API information
Route::get('/', function () {
    return response()->json([
        'name' => 'Laravel Matching API',
        'version' => '1.0.0',
        'status' => 'running',
        'documentation' => [
            'API Base URL' => url('/api'),
            'Endpoints' => [
                'POST /api/match' => 'Match tracking and video data',
                'GET /api/test' => 'Test with sample data',
                'GET /api/health' => 'Health check',
                'POST /api/hub/tracking-data' => 'Receive tracking data',
                'POST /api/hub/video-data' => 'Receive video data',
                'POST /api/hub/route-message' => 'Route message through hub',
                'GET /api/hub/status' => 'Get hub status',
                'GET /api/hub/test' => 'Test hub',
                'GET /api/rabbitMq' => 'Test RabbitMQ',
            ],
            'Note' => 'All API endpoints are prefixed with /api'
        ],
        'database' => [
            'connection' => config('database.default'),
            'status' => 'Check /api/health for database status'
        ]
    ], 200, [], JSON_PRETTY_PRINT);
});
