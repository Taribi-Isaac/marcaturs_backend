<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $report = $checker->check();

        return ApiResponse::success(
            $report['data'],
            $report['operational'] ? 200 : 503,
        );
    }
}
