<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Support\Signer;

class ManifestController extends Controller
{
    /** Public and unsigned by design — a signature requirement here would make trusting a new peer depend on already trusting it. */
    public function show(ManifestRegistry $registry, Signer $signer): JsonResponse
    {
        $manifest = $registry->build();
        $manifest['public_key'] = $signer->publicKey();

        RoutesRegistered::dispatch($manifest['service'], $manifest['routes']);

        return response()->json($manifest);
    }
}
