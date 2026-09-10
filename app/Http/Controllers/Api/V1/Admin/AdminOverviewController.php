<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOverviewService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminOverviewController extends Controller
{
    public function __construct(
        private readonly AdminOverviewService $overview,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->overview->build());
    }
}
