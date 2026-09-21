<?php

namespace Tests\Feature\Certification;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationLessonProgressStatus;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\CertificationResourceType;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationEnrollment;
use App\Models\CertificationLesson;
use App\Models\CertificationLessonProgress;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationLearningProgressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     ambassador: User,
     *     programme: CertificationProgramme,
     *     version: CertificationProgrammeVersion,
     *     enrollment: CertificationEnrollment,
     *     module: CertificationModule,
     *     requiredA: CertificationLesson,
     *     requiredB: CertificationLesson,
     *     optional: CertificationLesson,
     *     resource: CertificationResource
     * }
     */
    private function enrolledCurriculum(): array
    {
        Storage::fake((string) config('certification.resource_disk'));

        $ambassador = User::factory()->ambassador()->create();
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'fee_amount_minor' => 1500000,
            'pass_mark_percent' => 70,
        ]);
        $programme->current_published_version_id = $version->id;
        $programme->save();

        $module = CertificationModule::factory()->for($version, 'version')->create([
            'title' => 'NON-PRODUCTION Module',
            'sort_order' => 1,
        ]);
        $requiredA = CertificationLesson::factory()->for($module, 'module')->create([
            'title' => 'NON-PRODUCTION Required A',
            'content_type' => CertificationLessonContentType::Text,
            'is_required' => true,
            'sort_order' => 1,
        ]);
        $requiredB = CertificationLesson::factory()->for($module, 'module')->create([
            'title' => 'NON-PRODUCTION Required B',
            'content_type' => CertificationLessonContentType::Text,
            'is_required' => true,
            'sort_order' => 2,
        ]);
        $optional = CertificationLesson::factory()->for($module, 'module')->create([
            'title' => 'NON-PRODUCTION Optional',
            'content_type' => CertificationLessonContentType::Downloadable,
            'is_required' => false,
            'sort_order' => 3,
        ]);

        $disk = (string) config('certification.resource_disk');
        $path = "certification/programmes/{$programme->id}/versions/{$version->id}/resources/example.bin";
        Storage::disk($disk)->put($path, 'synthetic');
        $resource = CertificationResource::factory()->for($optional, 'lesson')->create([
            'type' => CertificationResourceType::Downloadable,
            'title' => 'NON-PRODUCTION File',
            'sort_order' => 1,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => 'example.bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 8,
        ]);

        $enrollment = CertificationEnrollment::factory()->create([
            'user_id' => $ambassador->id,
            'programme_id' => $programme->id,
            'programme_version_id' => $version->id,
        ]);

        return compact(
            'ambassador',
            'programme',
            'version',
            'enrollment',
            'module',
            'requiredA',
            'requiredB',
            'optional',
            'resource',
        );
    }

    public function test_unauthenticated_and_business_cannot_access_learning_apis(): void
    {
        $stack = $this->enrolledCurriculum();
        $enrollmentId = $stack['enrollment']->id;

        $this->getJson("/api/v1/certification/enrollments/{$enrollmentId}/curriculum")
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson("/api/v1/certification/enrollments/{$enrollmentId}/curriculum")
            ->assertStatus(403);
        $this->getJson("/api/v1/certification/enrollments/{$enrollmentId}/progress")
            ->assertStatus(403);
        $this->postJson("/api/v1/certification/enrollments/{$enrollmentId}/lessons/{$stack['requiredA']->id}/complete")
            ->assertStatus(403);
    }

    public function test_non_owner_and_non_enrolled_ambassador_cannot_access_curriculum(): void
    {
        $stack = $this->enrolledCurriculum();
        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);

        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/curriculum")
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$stack['requiredA']->id}/complete")
            ->assertStatus(404);
    }

    public function test_restricted_account_follows_global_account_access(): void
    {
        $stack = $this->enrolledCurriculum();
        $stack['ambassador']->status = AccountStatus::Restricted;
        $stack['ambassador']->save();
        Sanctum::actingAs($stack['ambassador']);

        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/curriculum")
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_enrolled_ambassador_can_retrieve_curriculum_and_progress(): void
    {
        $stack = $this->enrolledCurriculum();
        Sanctum::actingAs($stack['ambassador']);

        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/curriculum")
            ->assertOk()
            ->assertJsonPath('data.programme_version.id', $stack['version']->id)
            ->assertJsonPath('data.programme_version.version_number', 1)
            ->assertJsonPath('data.modules.0.lessons.0.progress.status', CertificationLessonProgressStatus::NotStarted->value)
            ->assertJsonPath('data.assessment_eligibility.eligible', false)
            ->assertJsonPath('data.assessment_eligibility.required_lessons', 2)
            ->assertJsonPath('data.assessment_eligibility.completed_required_lessons', 0)
            ->assertJsonPath('data.assessment_eligibility.optional_lessons', 1)
            ->assertJsonMissingPath('data.modules.0.lessons.0.resources.0.disk')
            ->assertJsonMissingPath('data.modules.0.lessons.0.resources.0.path');

        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.programme_version_id', $stack['version']->id)
            ->assertJsonCount(3, 'data.lessons')
            ->assertJsonPath('data.assessment_eligibility.eligible', false);
    }

    public function test_mark_complete_is_idempotent_and_unlocks_eligibility_without_optional(): void
    {
        $stack = $this->enrolledCurriculum();
        Sanctum::actingAs($stack['ambassador']);
        $base = "/api/v1/certification/enrollments/{$stack['enrollment']->id}";

        $first = $this->postJson("{$base}/lessons/{$stack['requiredA']->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', CertificationLessonProgressStatus::Completed->value)
            ->assertJsonPath('data.lesson_id', $stack['requiredA']->id);

        $completedAt = $first->json('data.completed_at');
        $this->assertNotNull($completedAt);

        $this->postJson("{$base}/lessons/{$stack['requiredA']->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.completed_at', $completedAt);

        $this->assertSame(1, CertificationLessonProgress::query()->count());
        $this->assertSame(
            1,
            CertificationAdminEvent::query()
                ->where('action', CertificationAdminEventAction::LessonCompleted)
                ->count(),
        );

        $this->getJson("{$base}/progress")
            ->assertOk()
            ->assertJsonPath('data.assessment_eligibility.eligible', false)
            ->assertJsonPath('data.assessment_eligibility.completed_required_lessons', 1);

        $this->postJson("{$base}/lessons/{$stack['requiredB']->id}/complete")->assertOk();

        $this->getJson("{$base}/progress")
            ->assertOk()
            ->assertJsonPath('data.assessment_eligibility.eligible', true)
            ->assertJsonPath('data.assessment_eligibility.required_lessons', 2)
            ->assertJsonPath('data.assessment_eligibility.completed_required_lessons', 2)
            ->assertJsonPath('data.assessment_eligibility.completed_optional_lessons', 0);

        $this->postJson("{$base}/lessons/{$stack['optional']->id}/complete")->assertOk();
        $this->getJson("{$base}/progress")
            ->assertOk()
            ->assertJsonPath('data.assessment_eligibility.eligible', true)
            ->assertJsonPath('data.assessment_eligibility.completed_optional_lessons', 1);
    }

    public function test_cannot_complete_lesson_from_another_programme_version(): void
    {
        $stack = $this->enrolledCurriculum();
        $otherVersion = CertificationProgrammeVersion::factory()
            ->for($stack['programme'], 'programme')
            ->create([
                'version_number' => 2,
                'status' => CertificationProgrammeVersionStatus::Draft,
                'fee_amount_minor' => 1500000,
                'pass_mark_percent' => 70,
            ]);
        $otherModule = CertificationModule::factory()->for($otherVersion, 'version')->create(['sort_order' => 1]);
        $foreignLesson = CertificationLesson::factory()->for($otherModule, 'module')->create([
            'title' => 'NON-PRODUCTION V2 Lesson',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($stack['ambassador']);
        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$foreignLesson->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->assertSame(0, CertificationLessonProgress::query()->count());
    }

    public function test_version_isolation_after_newer_version_published(): void
    {
        $stack = $this->enrolledCurriculum();
        Sanctum::actingAs($stack['ambassador']);
        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$stack['requiredA']->id}/complete")
            ->assertOk();

        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $v2 = $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 80,
        ])->assertCreated()->json('data');

        $v2Base = "/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/{$v2['version_number']}";
        $moduleId = $this->postJson("{$v2Base}/modules", ['title' => 'NON-PRODUCTION V2 Module'])
            ->assertCreated()
            ->json('data.id');
        $v2LessonId = $this->postJson("{$v2Base}/modules/{$moduleId}/lessons", [
            'title' => 'NON-PRODUCTION V2 Required',
            'content_type' => CertificationLessonContentType::Text->value,
            'is_required' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/{$v2['version_number']}/publish")
            ->assertOk();

        $this->assertSame($stack['version']->id, $stack['enrollment']->fresh()->programme_version_id);

        Sanctum::actingAs($stack['ambassador']);
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/curriculum")
            ->assertOk()
            ->assertJsonPath('data.programme_version.id', $stack['version']->id)
            ->assertJsonPath('data.programme_version.version_number', 1)
            ->assertJsonPath('data.assessment_eligibility.required_lessons', 2)
            ->assertJsonPath('data.assessment_eligibility.completed_required_lessons', 1)
            ->assertJsonPath('data.assessment_eligibility.eligible', false);

        $this->postJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$v2LessonId}/complete")
            ->assertStatus(404);

        $this->assertSame(1, CertificationLessonProgress::query()->count());
        $this->assertSame($stack['requiredA']->id, CertificationLessonProgress::query()->value('lesson_id'));
    }

    public function test_enrolled_ambassador_can_download_protected_resource(): void
    {
        $stack = $this->enrolledCurriculum();
        Sanctum::actingAs($stack['ambassador']);

        $this->get("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$stack['optional']->id}/resources/{$stack['resource']->id}/download")
            ->assertOk();

        $other = User::factory()->ambassador()->create();
        Sanctum::actingAs($other);
        $this->get("/api/v1/certification/enrollments/{$stack['enrollment']->id}/lessons/{$stack['optional']->id}/resources/{$stack['resource']->id}/download")
            ->assertStatus(404);
    }

    public function test_enrolled_version_remains_accessible_when_no_longer_current_published(): void
    {
        $stack = $this->enrolledCurriculum();
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions", [
            'fee_amount_minor' => 2500000,
            'pass_mark_percent' => 80,
        ])->assertCreated();
        $this->postJson("/api/v1/admin/certification/programmes/{$stack['programme']->id}/versions/2/publish")->assertOk();

        // Simulate historical enrolled version no longer being the current published pointer
        // (and optionally unpublished). Access must remain enrollment-bound.
        $stack['version']->forceFill([
            'status' => CertificationProgrammeVersionStatus::Unpublished,
            'unpublished_at' => now(),
        ])->save();

        Sanctum::actingAs($stack['ambassador']);
        $this->getJson("/api/v1/certification/enrollments/{$stack['enrollment']->id}/curriculum")
            ->assertOk()
            ->assertJsonPath('data.programme_version.id', $stack['version']->id)
            ->assertJsonPath('data.programme_version.status', CertificationProgrammeVersionStatus::Unpublished->value);
    }
}
