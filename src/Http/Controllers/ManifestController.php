<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Registry\ManifestRegistry;

class ManifestController extends Controller
{
    public function show(ManifestRegistry $registry): JsonResponse
    {
        $manifest = $registry->build();

        RoutesRegistered::dispatch($manifest['service'], $manifest['routes']);

        return response()->json($manifest);
    }
}
