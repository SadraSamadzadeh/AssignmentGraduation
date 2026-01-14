<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExternalApiController;
use App\Http\Controllers\MatchTestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

// Public authentication endpoints
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    
    // Protected authentication endpoints (require JWT token)
    Route::middleware('auth:api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });
});

/*
|--------------------------------------------------------------------------
| External API Integration Routes
|--------------------------------------------------------------------------
*/

// External API Integration Routes
Route::post('/external/login', [ExternalApiController::class, 'login']);
Route::get('/external/datasets/{datasetId}', [ExternalApiController::class, 'getDataset']);

/*
|--------------------------------------------------------------------------
| Match Testing Routes (Unauthenticated)
|--------------------------------------------------------------------------
*/

// Public endpoints for testing the matching algorithm
Route::prefix('match-test')->group(function () {
    Route::post('/compare', [MatchTestController::class, 'compareMatches'])
        ->name('match-test.compare');
    Route::get('/tracking-example', [MatchTestController::class, 'trackingDataExample'])
        ->name('match-test.tracking-example');
    Route::get('/video-example', [MatchTestController::class, 'videoDataExample'])
        ->name('match-test.video-example');
});