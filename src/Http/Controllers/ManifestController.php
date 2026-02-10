<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Jurager\Microservice\Registry\ManifestRegistry;

class ManifestController extends Controller
{
    public function store(Request $request, ManifestRegistry $registry): JsonResponse
    {
        $request->validate([
            'service' => 'required|string',
            'routes' => 'required|array',
            'routes.*.method' => 'required|string',
            'routes.*.uri' => 'required|string',
            'timestamp' => 'required|string',
        ]);

        $registry->store($request->all());

        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }

        return response()->json(['status' => 'registered']);
    }
}
