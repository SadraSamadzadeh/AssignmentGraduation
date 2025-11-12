<?php

use App\Http\Controllers\MatchController;
use App\Http\Controllers\HubController;
use Illuminate\Support\Facades\Route;
use App\Jobs\ProcessRabbitMQMessage;

// Simple API routes
Route::post('/match', [MatchController::class, 'match']);
Route::get('/test', [MatchController::class, 'test']);
Route::get('/health', [MatchController::class, 'health']);

// Central Hub Message Broker endpoints
Route::prefix('hub')->group(function () {
    // Endpoints for backends to send data to hub
    Route::post('/tracking-data', [HubController::class, 'receiveTrackingData']);
    Route::post('/video-data', [HubController::class, 'receiveVideoData']);
    
    // Endpoint for backends to communicate through hub
    Route::post('/route-message', [HubController::class, 'routeMessage']);
    
    // Hub status and testing
    Route::get('/status', [HubController::class, 'getHubStatus']);
    Route::get('/test', [HubController::class, 'testHub']);
});

Route::get('/rabbitMq', function() {
	ProcessRabbitMQMessage::dispatch();
	return 'Message sent to RabbitMQ!';
});