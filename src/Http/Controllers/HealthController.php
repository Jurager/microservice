<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Registry\HealthRegistry;

class HealthController extends Controller
{
    public function __invoke(HealthRegistry $registry): JsonResponse
    {
        return response()->json($registry->getAllHealth());
    }
}
