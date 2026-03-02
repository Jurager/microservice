<?php

use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Controllers\HealthController;
use Jurager\Microservice\Http\Controllers\ManifestController;
use Jurager\Microservice\Http\Middleware\TrustService;

Route::get('/microservice/manifest', [ManifestController::class, 'show'])
    ->middleware(TrustService::class);

if ($endpoint = config('microservice.health.endpoint')) {
    Route::get($endpoint, HealthController::class);
}
