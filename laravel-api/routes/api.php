<?php

use App\Http\Controllers\MatchController;
use Illuminate\Support\Facades\Route;

// Simple API routes
Route::post('/match', [MatchController::class, 'match']);
Route::get('/test', [MatchController::class, 'test']);
Route::get('/health', [MatchController::class, 'health']);