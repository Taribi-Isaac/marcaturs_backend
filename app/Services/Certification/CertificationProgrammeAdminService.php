<?php

namespace App\Services\Certification;

use App\Enums\AdminPermission;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CertificationProgrammeAdminService
{
    public function __construct(
        private readonly AdminAuthorization $authorization,
    ) {}

    /**
     * @return Collection<int, CertificationProgramme>
     */
    public function index(User $admin): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationView);

        return CertificationProgramme::query()
            ->with('currentPublishedVersion')
            ->orderByDesc('id')
            ->get();
    }

    public function show(User $admin, CertificationProgramme $programme): CertificationProgramme
    {
        $this->authorization->assert($admin, AdminPermission::CertificationView);

        return $programme->load(['currentPublishedVersion', 'versions' => fn ($q) => $q->orderBy('version_number')]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $admin, array $attributes): CertificationProgramme
    {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);

        return DB::transaction(function () use ($admin, $attributes) {
            $programme = new CertificationProgramme;
            $programme->name = (string) $attributes['name'];
            $programme->description = $attributes['description'] ?? null;
            $programme->learning_objectives = $attributes['learning_objectives'] ?? null;
            $programme->status = CertificationProgrammeStatus::Draft;
            $programme->created_by_user_id = $admin->id;
            $programme->save();

            $this->recordEvent(
                $admin,
                $programme,
                null,
                CertificationAdminEventAction::ProgrammeCreated,
                [
                    'name' => $programme->name,
                    'status' => $programme->status->value,
                ],
            );

            return $programme->fresh(['currentPublishedVersion']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $admin, CertificationProgramme $programme, array $attributes): CertificationProgramme
    {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);

        return DB::transaction(function () use ($admin, $programme, $attributes) {
            $locked = CertificationProgramme::query()->whereKey($programme->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $locked->status;

            if (array_key_exists('name', $attributes)) {
                $locked->name = (string) $attributes['name'];
            }
            if (array_key_exists('description', $attributes)) {
                $locked->description = $attributes['description'];
            }
            if (array_key_exists('learning_objectives', $attributes)) {
                $locked->learning_objectives = $attributes['learning_objectives'];
            }

            $action = CertificationAdminEventAction::ProgrammeUpdated;

            if (array_key_exists('status', $attributes) && $attributes['status'] !== null) {
                $next = CertificationProgrammeStatus::from((string) $attributes['status']);
                $this->assertProgrammeStatusTransition($locked, $next);
                $locked->status = $next;
                $action = $next === CertificationProgrammeStatus::Archived
                    ? CertificationAdminEventAction::ProgrammeArchived
                    : CertificationAdminEventAction::ProgrammeUnarchived;
            }

            $locked->save();

            $this->recordEvent(
                $admin,
                $locked,
                null,
                $action,
                [
                    'previous_status' => $previousStatus->value,
                    'status' => $locked->status->value,
                    'changed' => array_keys($attributes),
                ],
            );

            return $locked->fresh(['currentPublishedVersion']);
        });
    }

    /**
     * @return Collection<int, CertificationProgrammeVersion>
     */
    public function indexVersions(User $admin, CertificationProgramme $programme): Collection
    {
        $this->authorization->assert($admin, AdminPermission::CertificationView);

        return $programme->versions()->orderBy('version_number')->get();
    }

    public function showVersion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationProgrammeVersion {
        $this->authorization->assert($admin, AdminPermission::CertificationView);
        $this->assertBelongsToProgramme($programme, $version);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createVersion(
        User $admin,
        CertificationProgramme $programme,
        array $attributes,
    ): CertificationProgrammeVersion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);

        if ($programme->status === CertificationProgrammeStatus::Archived) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Versions cannot be created for an archived programme.',
                409,
            ));
        }

        try {
            return DB::transaction(function () use ($admin, $programme, $attributes) {
                $locked = CertificationProgramme::query()->whereKey($programme->id)->lockForUpdate()->firstOrFail();

                if ($locked->versions()->where('status', CertificationProgrammeVersionStatus::Draft->value)->exists()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'A draft version already exists. Update or publish it before creating another version.',
                        409,
                    ));
                }

                $next = (int) $locked->versions()->max('version_number') + 1;

                $version = new CertificationProgrammeVersion;
                $version->programme_id = $locked->id;
                $version->version_number = $next;
                $version->status = CertificationProgrammeVersionStatus::Draft;
                $version->fee_currency = strtoupper((string) ($attributes['fee_currency'] ?? 'NGN'));
                $version->created_by_user_id = $admin->id;
                $this->applyCommercialAttributes($version, $attributes);
                $version->save();

                $this->recordEvent(
                    $admin,
                    $locked,
                    $version,
                    CertificationAdminEventAction::VersionCreated,
                    [
                        'version_number' => $version->version_number,
                        'status' => $version->status->value,
                    ],
                );

                return $version->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'A programme version with this number already exists.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVersion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
        array $attributes,
    ): CertificationProgrammeVersion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertBelongsToProgramme($programme, $version);
        $this->assertMutable($version);

        return DB::transaction(function () use ($admin, $programme, $version, $attributes) {
            $locked = CertificationProgrammeVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToProgramme($programme, $locked);
            $this->assertMutable($locked);

            $this->applyCommercialAttributes($locked, $attributes);
            $locked->save();

            $this->recordEvent(
                $admin,
                $programme,
                $locked,
                CertificationAdminEventAction::VersionUpdated,
                [
                    'version_number' => $locked->version_number,
                    'changed' => array_keys($attributes),
                    'fee_amount_minor' => $locked->fee_amount_minor,
                    'fee_currency' => $locked->fee_currency,
                    'pass_mark_percent' => $locked->pass_mark_percent,
                ],
            );

            return $locked->refresh();
        });
    }

    public function publishVersion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationProgrammeVersion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertBelongsToProgramme($programme, $version);
        $this->assertMutable($version);

        if ($programme->status === CertificationProgrammeStatus::Archived) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Archived programmes cannot publish versions.',
                409,
            ));
        }

        return DB::transaction(function () use ($admin, $programme, $version) {
            $lockedProgramme = CertificationProgramme::query()->whereKey($programme->id)->lockForUpdate()->firstOrFail();
            $lockedVersion = CertificationProgrammeVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToProgramme($lockedProgramme, $lockedVersion);
            $this->assertMutable($lockedVersion);
            $this->assertPublishable($lockedVersion);

            $lockedVersion->status = CertificationProgrammeVersionStatus::Published;
            $lockedVersion->published_at = now();
            $lockedVersion->unpublished_at = null;
            $lockedVersion->save();

            $lockedProgramme->current_published_version_id = $lockedVersion->id;
            $lockedProgramme->status = CertificationProgrammeStatus::Published;
            $lockedProgramme->save();

            $this->recordEvent(
                $admin,
                $lockedProgramme,
                $lockedVersion,
                CertificationAdminEventAction::VersionPublished,
                [
                    'version_number' => $lockedVersion->version_number,
                    'fee_amount_minor' => $lockedVersion->fee_amount_minor,
                    'fee_currency' => $lockedVersion->fee_currency,
                    'pass_mark_percent' => $lockedVersion->pass_mark_percent,
                ],
            );

            return $lockedVersion->refresh();
        });
    }

    public function unpublishVersion(
        User $admin,
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): CertificationProgrammeVersion {
        $this->authorization->assert($admin, AdminPermission::CertificationManage);
        $this->assertBelongsToProgramme($programme, $version);

        return DB::transaction(function () use ($admin, $programme, $version) {
            $lockedProgramme = CertificationProgramme::query()->whereKey($programme->id)->lockForUpdate()->firstOrFail();
            $lockedVersion = CertificationProgrammeVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToProgramme($lockedProgramme, $lockedVersion);

            if ($lockedVersion->status !== CertificationProgrammeVersionStatus::Published) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'Only published versions can be unpublished.',
                    409,
                ));
            }

            if ((int) $lockedProgramme->current_published_version_id !== (int) $lockedVersion->id) {
                throw new HttpResponseException(ApiResponse::error(
                    ApiErrorCode::CONFLICT,
                    'Only the current published version can be unpublished.',
                    409,
                ));
            }

            $lockedVersion->status = CertificationProgrammeVersionStatus::Unpublished;
            $lockedVersion->unpublished_at = now();
            $lockedVersion->save();

            $lockedProgramme->current_published_version_id = null;
            $lockedProgramme->status = CertificationProgrammeStatus::Unpublished;
            $lockedProgramme->save();

            $this->recordEvent(
                $admin,
                $lockedProgramme,
                $lockedVersion,
                CertificationAdminEventAction::VersionUnpublished,
                [
                    'version_number' => $lockedVersion->version_number,
                ],
            );

            return $lockedVersion->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyCommercialAttributes(CertificationProgrammeVersion $version, array $attributes): void
    {
        if (array_key_exists('fee_amount_minor', $attributes)) {
            $version->fee_amount_minor = $attributes['fee_amount_minor'] === null
                ? null
                : (int) $attributes['fee_amount_minor'];
        }

        if (array_key_exists('fee_currency', $attributes) && $attributes['fee_currency'] !== null) {
            $version->fee_currency = strtoupper((string) $attributes['fee_currency']);
        }

        if (array_key_exists('pass_mark_percent', $attributes)) {
            $version->pass_mark_percent = $attributes['pass_mark_percent'] === null
                ? null
                : $attributes['pass_mark_percent'];
        }
    }

    private function assertPublishable(CertificationProgrammeVersion $version): void
    {
        $validator = validator([
            'fee_amount_minor' => $version->fee_amount_minor,
            'fee_currency' => $version->fee_currency,
            'pass_mark_percent' => $version->pass_mark_percent,
        ], [
            'fee_amount_minor' => ['required', 'integer', 'min:1'],
            'fee_currency' => ['required', 'string', 'size:3'],
            'pass_mark_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'fee_amount_minor.required' => 'A programme fee must be configured before publishing.',
            'pass_mark_percent.required' => 'A pass mark must be configured before publishing.',
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(ApiResponse::validation($validator));
        }
    }

    private function assertProgrammeStatusTransition(
        CertificationProgramme $programme,
        CertificationProgrammeStatus $next,
    ): void {
        if ($next === CertificationProgrammeStatus::Archived && ! $programme->status->allowsArchive()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only unpublished programmes can be archived.',
                409,
            ));
        }

        if ($next === CertificationProgrammeStatus::Unpublished && ! $programme->status->allowsUnarchive()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Only archived programmes can be restored to unpublished.',
                409,
            ));
        }

        if (! in_array($next, [
            CertificationProgrammeStatus::Archived,
            CertificationProgrammeStatus::Unpublished,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only archive and unarchive transitions are allowed via programme update.'],
            ]);
        }
    }

    private function assertBelongsToProgramme(
        CertificationProgramme $programme,
        CertificationProgrammeVersion $version,
    ): void {
        if ((int) $version->programme_id !== (int) $programme->id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }
    }

    private function assertMutable(CertificationProgrammeVersion $version): void
    {
        if (! $version->status->isMutable()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Published and unpublished programme versions are immutable.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordEvent(
        User $admin,
        CertificationProgramme $programme,
        ?CertificationProgrammeVersion $version,
        CertificationAdminEventAction $action,
        ?array $payload = null,
    ): void {
        $event = new CertificationAdminEvent;
        $event->actor_user_id = $admin->id;
        $event->programme_id = $programme->id;
        $event->programme_version_id = $version?->id;
        $event->action = $action;
        $event->payload = $payload;
        $event->save();
    }
}
