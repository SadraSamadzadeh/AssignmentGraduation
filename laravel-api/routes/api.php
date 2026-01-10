<?php

use App\Http\Controllers\ExternalApiController;
use Illuminate\Support\Facades\Route;

// External API Integration Routes
Route::post('/external/login', [ExternalApiController::class, 'login']);
Route::get('/external/datasets/{datasetId}', [ExternalApiController::class, 'getDataset']);