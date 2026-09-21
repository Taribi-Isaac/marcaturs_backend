<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationResourceType;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationLesson;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificationCurriculumAdminService
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
        private readonly CertificationResourceStore $files,
    ) {}

    /**
     * @return Collection<int, CertificationModule>
     */
    public function indexModules(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertVersionBelongs($programme, $version);

        return $version->modules()->with(['lessons.resources'])->orderBy('sort_order')->get();
    }

    public function showModule(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): CertificationModule {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertModuleChain($programme, $version, $module);

        return $module->load(['lessons.resources']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createModule(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $attributes,
    ): CertificationModule {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionBelongs($programme, $version);
        $this->assertVersionMutable($version);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $attributes) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);

                $module = new CertificationModule;
                $module->programme_version_id = $lockedVersion->id;
                $module->title = (string) $attributes['title'];
                $module->description = $attributes['description'] ?? null;
                $module->sort_order = (int) $lockedVersion->modules()->max('sort_order') + 1;
                $module->save();

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::ModuleCreated,
                    ['module_id' => $module->id, 'title' => $module->title, 'sort_order' => $module->sort_order],
                );

                return $module->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateModule(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        array $attributes,
    ): CertificationModule {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertModuleChain($programme, $version, $module);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $module, $attributes) {
            $this->lockMutableVersion($programme, $version);
            $locked = CertificationModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
            $this->assertModuleChain($programme, $version, $locked);

            if (array_key_exists('title', $attributes)) {
                $locked->title = (string) $attributes['title'];
            }
            if (array_key_exists('description', $attributes)) {
                $locked->description = $attributes['description'];
            }
            $locked->save();

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::ModuleUpdated,
                ['module_id' => $locked->id, 'changed' => array_keys($attributes)],
            );

            return $locked->refresh();
        });
    }

    public function deleteModule(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): void {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertModuleChain($programme, $version, $module);
        $this->assertVersionMutable($version);

        DB::transaction(function () use ($admin, $programme, $version, $module) {
            $lockedVersion = $this->lockMutableVersion($programme, $version);
            $locked = CertificationModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
            $this->assertModuleChain($programme, $lockedVersion, $locked);

            $lessons = $locked->lessons()->with('resources')->get();
            foreach ($lessons as $lesson) {
                foreach ($lesson->resources as $resource) {
                    $this->files->deleteFile($resource);
                    $resource->delete();
                }
                $lesson->delete();
            }

            $moduleId = $locked->id;
            $locked->delete();
            $this->renumberModules($lockedVersion);

            $this->recordEvent(
                $admin,
                $programme,
                $lockedVersion,
                CertificationAdminEventAction::ModuleDeleted,
                ['module_id' => $moduleId],
            );
        });
    }

    /**
     * @param  list<int>  $orderedIds
     * @return Collection<int, CertificationModule>
     */
    public function reorderModules(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $orderedIds,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertVersionBelongs($programme, $version);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $orderedIds) {
            $lockedVersion = $this->lockMutableVersion($programme, $version);
            $modules = $lockedVersion->modules()->lockForUpdate()->orderBy('sort_order')->get();
            $this->assertExactIdSet($modules->pluck('id')->all(), $orderedIds, 'module_ids');

            foreach ($modules as $index => $module) {
                $module->sort_order = 1_000_000 + $index;
                $module->save();
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $module = $modules->firstWhere('id', $id);
                $module->sort_order = $index + 1;
                $module->save();
            }

            $this->recordEvent(
                $admin,
                $programme,
                $lockedVersion,
                CertificationAdminEventAction::ModulesReordered,
                ['module_ids' => array_values($orderedIds)],
            );

            return $lockedVersion->modules()->orderBy('sort_order')->get();
        });
    }

    /**
     * @return Collection<int, CertificationLesson>
     */
    public function indexLessons(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertModuleChain($programme, $version, $module);

        return $module->lessons()->with('resources')->orderBy('sort_order')->get();
    }

    public function showLesson(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): CertificationLesson {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertLessonChain($programme, $version, $module, $lesson);

        return $lesson->load('resources');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createLesson(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        array $attributes,
    ): CertificationLesson {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertModuleChain($programme, $version, $module);
        $this->assertVersionMutable($version);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $module, $attributes) {
                $this->lockMutableVersion($programme, $version);
                $lockedModule = CertificationModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
                $this->assertModuleChain($programme, $version, $lockedModule);

                $lesson = new CertificationLesson;
                $lesson->module_id = $lockedModule->id;
                $lesson->title = (string) $attributes['title'];
                $lesson->description = $attributes['description'] ?? null;
                $lesson->content_type = $attributes['content_type'] instanceof CertificationLessonContentType
                    ? $attributes['content_type']
                    : CertificationLessonContentType::from((string) $attributes['content_type']);
                $lesson->is_required = array_key_exists('is_required', $attributes)
                    ? (bool) $attributes['is_required']
                    : true;
                $lesson->sort_order = (int) $lockedModule->lessons()->max('sort_order') + 1;
                $lesson->save();

                $this->recordEvent(
                    $admin,
                    $programme,
                    $version,
                    CertificationAdminEventAction::LessonCreated,
                    [
                        'module_id' => $lockedModule->id,
                        'lesson_id' => $lesson->id,
                        'content_type' => $lesson->content_type->value,
                        'is_required' => $lesson->is_required,
                    ],
                );

                return $lesson->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateLesson(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        array $attributes,
    ): CertificationLesson {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertLessonChain($programme, $version, $module, $lesson);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $module, $lesson, $attributes) {
            $this->lockMutableVersion($programme, $version);
            $locked = CertificationLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $this->assertLessonChain($programme, $version, $module, $locked);

            if (array_key_exists('title', $attributes)) {
                $locked->title = (string) $attributes['title'];
            }
            if (array_key_exists('description', $attributes)) {
                $locked->description = $attributes['description'];
            }
            if (array_key_exists('content_type', $attributes)) {
                $locked->content_type = $attributes['content_type'] instanceof CertificationLessonContentType
                    ? $attributes['content_type']
                    : CertificationLessonContentType::from((string) $attributes['content_type']);
            }
            if (array_key_exists('is_required', $attributes)) {
                $locked->is_required = (bool) $attributes['is_required'];
            }
            $locked->save();

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::LessonUpdated,
                ['lesson_id' => $locked->id, 'changed' => array_keys($attributes)],
            );

            return $locked->refresh();
        });
    }

    public function deleteLesson(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): void {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertLessonChain($programme, $version, $module, $lesson);
        $this->assertVersionMutable($version);

        DB::transaction(function () use ($admin, $programme, $version, $module, $lesson) {
            $this->lockMutableVersion($programme, $version);
            $locked = CertificationLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $this->assertLessonChain($programme, $version, $module, $locked);

            foreach ($locked->resources()->get() as $resource) {
                $this->files->deleteFile($resource);
                $resource->delete();
            }

            $lessonId = $locked->id;
            $locked->delete();
            $this->renumberLessons($module);

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::LessonDeleted,
                ['lesson_id' => $lessonId, 'module_id' => $module->id],
            );
        });
    }

    /**
     * @param  list<int>  $orderedIds
     * @return Collection<int, CertificationLesson>
     */
    public function reorderLessons(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        array $orderedIds,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertModuleChain($programme, $version, $module);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $module, $orderedIds) {
            $this->lockMutableVersion($programme, $version);
            $lockedModule = CertificationModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
            $lessons = $lockedModule->lessons()->lockForUpdate()->orderBy('sort_order')->get();
            $this->assertExactIdSet($lessons->pluck('id')->all(), $orderedIds, 'lesson_ids');

            foreach ($lessons as $index => $lesson) {
                $lesson->sort_order = 1_000_000 + $index;
                $lesson->save();
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $lesson = $lessons->firstWhere('id', $id);
                $lesson->sort_order = $index + 1;
                $lesson->save();
            }

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::LessonsReordered,
                ['module_id' => $module->id, 'lesson_ids' => array_values($orderedIds)],
            );

            return $lockedModule->lessons()->orderBy('sort_order')->get();
        });
    }

    /**
     * @return Collection<int, CertificationResource>
     */
    public function indexResources(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertLessonChain($programme, $version, $module, $lesson);

        return $lesson->resources()->orderBy('sort_order')->get();
    }

    public function showResource(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): CertificationResource {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertResourceChain($programme, $version, $module, $lesson, $resource);

        return $resource;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createResource(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        array $attributes,
        ?UploadedFile $file = null,
    ): CertificationResource {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertLessonChain($programme, $version, $module, $lesson);
        $this->assertVersionMutable($version);

        $type = $attributes['type'] instanceof CertificationResourceType
            ? $attributes['type']
            : CertificationResourceType::from((string) $attributes['type']);

        $this->assertResourcePayload($type, $attributes, $file, requireFile: true);

        try {
            return DB::transaction(function () use ($admin, $programme, $version, $module, $lesson, $attributes, $file, $type) {
                $lockedVersion = $this->lockMutableVersion($programme, $version);
                $lockedLesson = CertificationLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
                $this->assertLessonChain($programme, $lockedVersion, $module, $lockedLesson);

                $resource = new CertificationResource;
                $resource->lesson_id = $lockedLesson->id;
                $resource->type = $type;
                $resource->title = (string) $attributes['title'];
                $resource->sort_order = (int) $lockedLesson->resources()->max('sort_order') + 1;
                $this->applyResourceContent($resource, $type, $attributes, $file, $lockedVersion);
                $resource->save();

                $this->recordEvent(
                    $admin,
                    $programme,
                    $lockedVersion,
                    CertificationAdminEventAction::ResourceCreated,
                    [
                        'lesson_id' => $lockedLesson->id,
                        'resource_id' => $resource->id,
                        'type' => $resource->type->value,
                    ],
                );

                return $resource->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->orderingConflict();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateResource(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
        array $attributes,
        ?UploadedFile $file = null,
    ): CertificationResource {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertResourceChain($programme, $version, $module, $lesson, $resource);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $module, $lesson, $resource, $attributes, $file) {
            $lockedVersion = $this->lockMutableVersion($programme, $version);
            $locked = CertificationResource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $this->assertResourceChain($programme, $lockedVersion, $module, $lesson, $locked);

            $type = array_key_exists('type', $attributes)
                ? ($attributes['type'] instanceof CertificationResourceType
                    ? $attributes['type']
                    : CertificationResourceType::from((string) $attributes['type']))
                : $locked->type;

            $merged = [
                'title' => $attributes['title'] ?? $locked->title,
                'body_text' => array_key_exists('body_text', $attributes) ? $attributes['body_text'] : $locked->body_text,
                'external_url' => array_key_exists('external_url', $attributes) ? $attributes['external_url'] : $locked->external_url,
            ];
            $this->assertResourcePayload($type, $merged, $file, requireFile: false);

            if (array_key_exists('title', $attributes)) {
                $locked->title = (string) $attributes['title'];
            }

            $previousType = $locked->type;
            $locked->type = $type;
            $this->applyResourceContent($locked, $type, $attributes + [
                'body_text' => $merged['body_text'],
                'external_url' => $merged['external_url'],
            ], $file, $lockedVersion, $previousType);

            $locked->save();

            $this->recordEvent(
                $admin,
                $programme,
                $lockedVersion,
                CertificationAdminEventAction::ResourceUpdated,
                ['resource_id' => $locked->id, 'changed' => array_keys($attributes)],
            );

            return $locked->refresh();
        });
    }

    public function deleteResource(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): void {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertResourceChain($programme, $version, $module, $lesson, $resource);
        $this->assertVersionMutable($version);

        DB::transaction(function () use ($admin, $programme, $version, $module, $lesson, $resource) {
            $this->lockMutableVersion($programme, $version);
            $locked = CertificationResource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $this->assertResourceChain($programme, $version, $module, $lesson, $locked);

            $resourceId = $locked->id;
            $this->files->deleteFile($locked);
            $locked->delete();
            $this->renumberResources($lesson);

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::ResourceDeleted,
                ['resource_id' => $resourceId, 'lesson_id' => $lesson->id],
            );
        });
    }

    /**
     * @param  list<int>  $orderedIds
     * @return Collection<int, CertificationResource>
     */
    public function reorderResources(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        array $orderedIds,
    ): Collection {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertLessonChain($programme, $version, $module, $lesson);
        $this->assertVersionMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $lesson, $orderedIds) {
            $this->lockMutableVersion($programme, $version);
            $lockedLesson = CertificationLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $resources = $lockedLesson->resources()->lockForUpdate()->orderBy('sort_order')->get();
            $this->assertExactIdSet($resources->pluck('id')->all(), $orderedIds, 'resource_ids');

            foreach ($resources as $index => $resource) {
                $resource->sort_order = 1_000_000 + $index;
                $resource->save();
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $resource = $resources->firstWhere('id', $id);
                $resource->sort_order = $index + 1;
                $resource->save();
            }

            $this->recordEvent(
                $admin,
                $programme,
                $version,
                CertificationAdminEventAction::ResourcesReordered,
                ['lesson_id' => $lesson->id, 'resource_ids' => array_values($orderedIds)],
            );

            return $lockedLesson->resources()->orderBy('sort_order')->get();
        });
    }

    public function downloadResource(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): StreamedResponse {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertResourceChain($programme, $version, $module, $lesson, $resource);

        if (! $resource->hasPrivateFile()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }

        return $this->files->stream($resource);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyResourceContent(
        CertificationResource $resource,
        CertificationResourceType $type,
        array $attributes,
        ?UploadedFile $file,
        CertificationProgrammeVersion $version,
        ?CertificationResourceType $previousType = null,
    ): void {
        if ($type->requiresBodyText()) {
            $resource->body_text = isset($attributes['body_text']) ? (string) $attributes['body_text'] : $resource->body_text;
            $resource->external_url = null;
            if ($previousType === CertificationResourceType::Downloadable || $resource->hasPrivateFile()) {
                $this->files->deleteFile($resource);
            }
            $resource->disk = null;
            $resource->path = null;
            $resource->original_filename = null;
            $resource->mime_type = null;
            $resource->size_bytes = null;

            return;
        }

        if ($type->requiresExternalUrl()) {
            if (array_key_exists('external_url', $attributes)) {
                $resource->external_url = (string) $attributes['external_url'];
            }
            $resource->body_text = null;
            if ($previousType === CertificationResourceType::Downloadable || $resource->hasPrivateFile()) {
                $this->files->deleteFile($resource);
            }
            $resource->disk = null;
            $resource->path = null;
            $resource->original_filename = null;
            $resource->mime_type = null;
            $resource->size_bytes = null;

            return;
        }

        if ($type->requiresFile()) {
            $resource->body_text = null;
            $resource->external_url = null;
            if ($file instanceof UploadedFile) {
                if ($resource->hasPrivateFile()) {
                    $this->files->deleteFile($resource);
                }
                $stored = $this->files->store($version, $file);
                $resource->disk = $stored['disk'];
                $resource->path = $stored['path'];
                $resource->original_filename = $stored['original_filename'];
                $resource->mime_type = $stored['mime_type'];
                $resource->size_bytes = $stored['size_bytes'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertResourcePayload(
        CertificationResourceType $type,
        array $attributes,
        ?UploadedFile $file,
        bool $requireFile,
    ): void {
        if ($type->requiresBodyText() && blank($attributes['body_text'] ?? null)) {
            throw new HttpResponseException(ApiResponse::validation(validator(
                ['body_text' => null],
                ['body_text' => ['required', 'string']],
            )));
        }

        if ($type->requiresExternalUrl() && blank($attributes['external_url'] ?? null)) {
            throw new HttpResponseException(ApiResponse::validation(validator(
                ['external_url' => null],
                ['external_url' => ['required', 'url', 'max:2048']],
            )));
        }

        if ($type->requiresFile() && $requireFile && ! $file instanceof UploadedFile) {
            throw new HttpResponseException(ApiResponse::validation(validator(
                ['file' => null],
                ['file' => ['required', 'file']],
            )));
        }
    }

    private function lockMutableVersion(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationProgrammeVersion {
        $locked = CertificationProgrammeVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
        $this->assertVersionBelongs($programme, $locked);
        $this->assertVersionMutable($locked);

        return $locked;
    }

    private function assertVersionMutable(CertificationProgrammeVersion $version): void
    {
        if (! $version->status->isMutable()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Curriculum belonging to published or unpublished programme versions is immutable.',
                409,
            ));
        }
    }

    private function assertVersionBelongs(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): void {
        if ((int) $version->programme_id !== (int) $programme->id) {
            throw $this->notFound();
        }
    }

    private function assertModuleChain(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
    ): void {
        $this->assertVersionBelongs($programme, $version);
        if ((int) $module->programme_version_id !== (int) $version->id) {
            throw $this->notFound();
        }
    }

    private function assertLessonChain(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
    ): void {
        $this->assertModuleChain($programme, $version, $module);
        if ((int) $lesson->module_id !== (int) $module->id) {
            throw $this->notFound();
        }
    }

    private function assertResourceChain(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationModule $module,
        CertificationLesson $lesson,
        CertificationResource $resource,
    ): void {
        $this->assertLessonChain($programme, $version, $module, $lesson);
        if ((int) $resource->lesson_id !== (int) $lesson->id) {
            throw $this->notFound();
        }
    }

    /**
     * @param  list<int|string>  $existing
     * @param  list<int|string>  $ordered
     */
    private function assertExactIdSet(array $existing, array $ordered, string $field): void
    {
        $existing = array_map('intval', $existing);
        $orderedInts = array_map('intval', $ordered);
        $sortedExisting = $existing;
        $sortedOrdered = $orderedInts;
        sort($sortedExisting);
        sort($sortedOrdered);

        if ($sortedExisting !== $sortedOrdered || count($orderedInts) !== count(array_unique($orderedInts))) {
            $validator = validator(
                [$field => $ordered],
                [$field => ['required', 'array']],
            );
            $validator->after(function ($validator) use ($field): void {
                $validator->errors()->add($field, 'The ordered id list must contain each item exactly once.');
            });
            $validator->fails();

            throw new HttpResponseException(ApiResponse::validation($validator));
        }
    }

    private function renumberModules(CertificationProgrammeVersion $version): void
    {
        foreach ($version->modules()->orderBy('sort_order')->orderBy('id')->get() as $index => $module) {
            $module->sort_order = 1_000_000 + $index;
            $module->save();
        }
        foreach ($version->modules()->orderBy('sort_order')->orderBy('id')->get() as $index => $module) {
            $module->sort_order = $index + 1;
            $module->save();
        }
    }

    private function renumberLessons(CertificationModule $module): void
    {
        foreach ($module->lessons()->orderBy('sort_order')->orderBy('id')->get() as $index => $lesson) {
            $lesson->sort_order = 1_000_000 + $index;
            $lesson->save();
        }
        foreach ($module->lessons()->orderBy('sort_order')->orderBy('id')->get() as $index => $lesson) {
            $lesson->sort_order = $index + 1;
            $lesson->save();
        }
    }

    private function renumberResources(CertificationLesson $lesson): void
    {
        foreach ($lesson->resources()->orderBy('sort_order')->orderBy('id')->get() as $index => $resource) {
            $resource->sort_order = 1_000_000 + $index;
            $resource->save();
        }
        foreach ($lesson->resources()->orderBy('sort_order')->orderBy('id')->get() as $index => $resource) {
            $resource->sort_order = $index + 1;
            $resource->save();
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordEvent(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        CertificationAdminEventAction $action,
        ?array $payload = null,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $admin->id;
        $event->programme_id = $programme->id;
        $event->programme_version_id = $version->id;
        $event->action = $action;
        $event->payload = $payload;
        $event->save();
    }

    private function notFound(): HttpResponseException
    {
        return new HttpResponseException(ApiResponse::error(
            ApiErrorCode::NOT_FOUND,
            'The requested resource was not found.',
            404,
        ));
    }

    private function orderingConflict(): HttpResponseException
    {
        return new HttpResponseException(ApiResponse::error(
            ApiErrorCode::CONFLICT,
            'Curriculum ordering conflict. Retry the request.',
            409,
        ));
    }
}
