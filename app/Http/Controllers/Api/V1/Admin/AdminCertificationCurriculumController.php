<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Certification\ReorderCertificationLessonsRequest;
use App\Http\Requests\Api\V1\Admin\Certification\ReorderCertificationModulesRequest;
use App\Http\Requests\Api\V1\Admin\Certification\ReorderCertificationResourcesRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationLessonRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationModuleRequest;
use App\Http\Requests\Api\V1\Admin\Certification\StoreCertificationResourceRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationLessonRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationModuleRequest;
use App\Http\Requests\Api\V1\Admin\Certification\UpdateCertificationResourceRequest;
use App\Http\Resources\Api\V1\CertificationLessonResource;
use App\Http\Resources\Api\V1\CertificationModuleResource;
use App\Http\Resources\Api\V1\CertificationResourceResource;
use App\Models\CertificationLesson;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use App\Services\Certification\CertificationCurriculumAdminService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCertificationCurriculumController extends Controller
{
    public function __construct(
        private readonly CertificationCurriculumAdminService $curriculum,
    ) {}

    public function indexModules(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        return ApiResponse::success(
            CertificationModuleResource::collection(
                $this->curriculum->indexModules($request->user(), $programme, $version),
            )->resolve($request),
        );
    }

    public function storeModule(
        StoreCertificationModuleRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $module = $this->curriculum->createModule($request->user(), $programme, $version, $request->validated());

        return ApiResponse::success(
            (new CertificationModuleResource($module))->resolve($request),
            201,
        );
    }

    public function showModule(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        $module = $this->curriculum->showModule($request->user(), $programme, $version, $module);

        return ApiResponse::success(
            (new CertificationModuleResource($module))->resolve($request),
        );
    }

    public function updateModule(
        UpdateCertificationModuleRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        $updated = $this->curriculum->updateModule(
            $request->user(),
            $programme,
            $version,
            $module,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationModuleResource($updated))->resolve($request),
        );
    }

    public function destroyModule(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        $this->curriculum->deleteModule($request->user(), $programme, $version, $module);

        return ApiResponse::success(['deleted' => true]);
    }

    public function reorderModules(
        ReorderCertificationModulesRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): JsonResponse {
        $modules = $this->curriculum->reorderModules(
            $request->user(),
            $programme,
            $version,
            $request->validated('module_ids'),
        );

        return ApiResponse::success(
            CertificationModuleResource::collection($modules)->resolve($request),
        );
    }

    public function indexLessons(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        return ApiResponse::success(
            CertificationLessonResource::collection(
                $this->curriculum->indexLessons($request->user(), $programme, $version, $module),
            )->resolve($request),
        );
    }

    public function storeLesson(
        StoreCertificationLessonRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        $lesson = $this->curriculum->createLesson(
            $request->user(),
            $programme,
            $version,
            $module,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationLessonResource($lesson))->resolve($request),
            201,
        );
    }

    public function showLesson(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        $lesson = $this->curriculum->showLesson($request->user(), $programme, $version, $module, $lesson);

        return ApiResponse::success(
            (new CertificationLessonResource($lesson))->resolve($request),
        );
    }

    public function updateLesson(
        UpdateCertificationLessonRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        $updated = $this->curriculum->updateLesson(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $request->validated(),
        );

        return ApiResponse::success(
            (new CertificationLessonResource($updated))->resolve($request),
        );
    }

    public function destroyLesson(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        $this->curriculum->deleteLesson($request->user(), $programme, $version, $module, $lesson);

        return ApiResponse::success(['deleted' => true]);
    }

    public function reorderLessons(
        ReorderCertificationLessonsRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): JsonResponse {
        $lessons = $this->curriculum->reorderLessons(
            $request->user(),
            $programme,
            $version,
            $module,
            $request->validated('lesson_ids'),
        );

        return ApiResponse::success(
            CertificationLessonResource::collection($lessons)->resolve($request),
        );
    }

    public function indexResources(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        return ApiResponse::success(
            CertificationResourceResource::collection(
                $this->curriculum->indexResources($request->user(), $programme, $version, $module, $lesson),
            )->resolve($request),
        );
    }

    public function storeResource(
        StoreCertificationResourceRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        $resource = $this->curriculum->createResource(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $request->validated(),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new CertificationResourceResource($resource))->resolve($request),
            201,
        );
    }

    public function showResource(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): JsonResponse {
        $resource = $this->curriculum->showResource(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $resource,
        );

        return ApiResponse::success(
            (new CertificationResourceResource($resource))->resolve($request),
        );
    }

    public function updateResource(
        UpdateCertificationResourceRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): JsonResponse {
        $updated = $this->curriculum->updateResource(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $resource,
            $request->validated(),
            $request->file('file'),
        );

        return ApiResponse::success(
            (new CertificationResourceResource($updated))->resolve($request),
        );
    }

    public function destroyResource(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): JsonResponse {
        $this->curriculum->deleteResource(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $resource,
        );

        return ApiResponse::success(['deleted' => true]);
    }

    public function reorderResources(
        ReorderCertificationResourcesRequest $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): JsonResponse {
        $resources = $this->curriculum->reorderResources(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $request->validated('resource_ids'),
        );

        return ApiResponse::success(
            CertificationResourceResource::collection($resources)->resolve($request),
        );
    }

    public function downloadResource(
        Request $request,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): StreamedResponse {
        return $this->curriculum->downloadResource(
            $request->user(),
            $programme,
            $version,
            $module,
            $lesson,
            $resource,
        );
    }
}
