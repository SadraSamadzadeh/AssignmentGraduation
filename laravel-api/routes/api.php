<?php

use App\Http\Controllers\MatchController;
use App\Http\Controllers\MessageIngestionController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/match', [MatchController::class, 'match']);
Route::get('/test', [MatchController::class, 'test']);
Route::get('/health', [MatchController::class, 'health']);

Route::prefix('ingest')->group(function () {
    Route::post('/tracking', [MessageIngestionController::class, 'receiveTrackingData']);
    Route::post('/video', [MessageIngestionController::class, 'receiveVideoData']);
    Route::post('/start-consumer', [MessageIngestionController::class, 'startConsumer']);
    Route::get('/status', [MessageIngestionController::class, 'status']);
});

Route::prefix('dashboard')->group(function () {
    Route::get('/tracking/unmatched', [DashboardController::class, 'getUnmatchedTracking']);
    Route::get('/video/unmatched', [DashboardController::class, 'getUnmatchedVideo']);
    Route::get('/tracking/{trackingId}/cache', [DashboardController::class, 'getCachedTracking']);
    Route::get('/video/{videoId}/cache', [DashboardController::class, 'getCachedVideo']);
    Route::get('/cache/unmatched', [DashboardController::class, 'getUnmatchedCache']);
    Route::get('/stats', [DashboardController::class, 'getDashboardStats']);
});