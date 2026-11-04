<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Manifest\ManifestBuilder;

class ManifestController extends Controller
{
    public function show(ManifestBuilder $builder): JsonResponse
    {
        $manifest = $builder->build();

        RoutesRegistered::dispatch($manifest['service'], $manifest['routes']);

        return response()->json($manifest);
    }
}
