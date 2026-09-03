<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Verification\ResubmitVerificationSubmissionRequest;
use App\Http\Requests\Api\V1\Verification\StoreVerificationSubmissionRequest;
use App\Http\Resources\Api\V1\ParticipantVerificationSubmissionResource;
use App\Http\Resources\Api\V1\VerificationRequirementResource;
use App\Models\VerificationSubmission;
use App\Services\Verification\VerificationStatusCalculator;
use App\Services\Verification\VerificationSubmissionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationStatusCalculator $statuses,
        private readonly VerificationSubmissionService $submissions,
    ) {}

    public function requirements(Request $request): JsonResponse
    {
        $user = $request->user();

        $requirements = $this->statuses->activeRequirements($user->role);

        return ApiResponse::success(
            VerificationRequirementResource::collection($requirements)->resolve($request),
        );
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $requirements = $this->statuses->activeRequirements($user->role);
        $submissions = $user->verificationSubmissions()
            ->with(['requirement', 'evidence', 'versions'])
            ->get()
            ->keyBy('verification_requirement_id');

        $items = $requirements->map(function ($requirement) use ($submissions, $request) {
            $submission = $submissions->get($requirement->id);

            return [
                'requirement' => (new VerificationRequirementResource($requirement))->resolve($request),
                'submission' => $submission
                    ? (new ParticipantVerificationSubmissionResource($submission))->resolve($request)
                    : null,
            ];
        })->values();

        return ApiResponse::success([
            'overall_status' => $this->statuses->overall($user)->value,
            'requirements' => $items,
        ]);
    }

    public function store(StoreVerificationSubmissionRequest $request): JsonResponse
    {
        $submission = $this->submissions->submit(
            $request->user(),
            (int) $request->integer('requirement_id'),
            $request->input('text_value'),
            $request->file('evidence'),
        );

        return ApiResponse::success(
            (new ParticipantVerificationSubmissionResource($submission))->resolve($request),
            201,
        );
    }

    public function resubmit(ResubmitVerificationSubmissionRequest $request, VerificationSubmission $submission): JsonResponse
    {
        $updated = $this->submissions->resubmit(
            $request->user(),
            $submission,
            $request->input('text_value'),
            $request->file('evidence'),
        );

        return ApiResponse::success(
            (new ParticipantVerificationSubmissionResource($updated))->resolve($request),
        );
    }
}
