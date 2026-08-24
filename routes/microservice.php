<?php

use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Controllers\HealthController;
use Jurager\Microservice\Http\Controllers\ManifestController;
use Jurager\Microservice\Http\Controllers\MetricsController;
use Jurager\Microservice\Http\Middleware\TrustPeer;

Route::get('/microservice/manifest', [ManifestController::class, 'show'])
    ->middleware(TrustPeer::class);

if ($endpoint = config('microservice.health.endpoint')) {
    Route::get($endpoint, HealthController::class);
}

if ($endpoint = config('microservice.health.liveness_endpoint')) {
    Route::get($endpoint, [HealthController::class, 'live']);
}

if ($endpoint = config('microservice.health.readiness_endpoint')) {
    Route::get($endpoint, [HealthController::class, 'ready']);
}

if ($endpoint = config('microservice.health.metrics_endpoint')) {
    Route::get($endpoint, MetricsController::class);
}
