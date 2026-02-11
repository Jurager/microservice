<?php

use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Controllers\HealthController;
use Jurager\Microservice\Http\Controllers\ManifestController;
use Jurager\Microservice\Http\Middleware\TrustService;

Route::post('/microservice/manifest', [ManifestController::class, 'store'])
    ->middleware(TrustService::class);

if ($endpoint = config('microservice.health.endpoint')) {
    Route::get($endpoint, HealthController::class);
}
