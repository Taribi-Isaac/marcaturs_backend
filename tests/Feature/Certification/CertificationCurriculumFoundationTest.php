<?php

namespace Tests\Feature\Certification;

use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationLessonContentType;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Enums\CertificationResourceType;
use App\Models\CertificationLesson;
use App\Models\CertificationModule;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\CertificationResource;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationCurriculumFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function draftStack(): array
    {
        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Draft,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->create([
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Draft,
        ]);

        return [$programme, $version];
    }

    private function base(CertificationProgramme $programme, CertificationProgrammeVersion $version): string
    {
        return "/api/v1/admin/certification/programmes/{$programme->id}/versions/{$version->version_number}";
    }

    public function test_unauthenticated_and_unauthorized_curriculum_access_is_rejected(): void
    {
        [$programme, $version] = $this->draftStack();
        $base = $this->base($programme, $version);

        $this->getJson("{$base}/modules")
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->postJson("{$base}/modules", ['title' => 'Nope'])
            ->assertStatus(403);

        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Verification)->create());
        $this->getJson("{$base}/modules")
            ->assertStatus(403);
    }

    public function test_operations_can_manage_draft_curriculum_modules_lessons_and_resources(): void
    {
        Storage::fake((string) config('certification.resource_disk'));
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        [$programme, $version] = $this->draftStack();
        $base = $this->base($programme, $version);

        $this->postJson("{$base}/modules", [
            'title' => 'NON-PRODUCTION Module A',
            'description' => 'Synthetic module',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'NON-PRODUCTION Module A')
            ->assertJsonPath('data.sort_order', 1);

        $this->postJson("{$base}/modules", ['title' => 'NON-PRODUCTION Module B'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 2);

        $modules = $this->getJson("{$base}/modules")->assertOk()->json('data');
        $this->assertCount(2, $modules);
        $moduleA = $modules[0]['id'];
        $moduleB = $modules[1]['id'];

        $this->postJson("{$base}/modules/reorder", [
            'module_ids' => [$moduleB, $moduleA],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $moduleB)
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.1.id', $moduleA)
            ->assertJsonPath('data.1.sort_order', 2);

        $this->postJson("{$base}/modules/{$moduleB}/lessons", [
            'title' => 'NON-PRODUCTION Lesson 1',
            'content_type' => CertificationLessonContentType::Text->value,
            'is_required' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.content_type', 'text')
            ->assertJsonPath('data.is_required', true)
            ->assertJsonPath('data.sort_order', 1);

        $lessonId = (int) $this->getJson("{$base}/modules/{$moduleB}/lessons")->json('data.0.id');

        $this->postJson("{$base}/modules/{$moduleB}/lessons/{$lessonId}/resources", [
            'type' => CertificationResourceType::Text->value,
            'title' => 'NON-PRODUCTION Text Resource',
            'body_text' => 'Synthetic learning body only.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.body_text', 'Synthetic learning body only.')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $file = UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf');
        $this->post("{$base}/modules/{$moduleB}/lessons/{$lessonId}/resources", [
            'type' => CertificationResourceType::Downloadable->value,
            'title' => 'NON-PRODUCTION PDF',
            'file' => $file,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'downloadable')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.original_filename', 'guide.pdf');

        $resourceId = (int) $this->getJson("{$base}/modules/{$moduleB}/lessons/{$lessonId}/resources")
            ->json('data.1.id');

        $this->get("{$base}/modules/{$moduleB}/lessons/{$lessonId}/resources/{$resourceId}/download")
            ->assertOk();

        $this->postJson("{$base}/modules/{$moduleB}/lessons/{$lessonId}/resources", [
            'type' => CertificationResourceType::ExternalReference->value,
            'title' => 'NON-PRODUCTION External',
            'external_url' => 'https://example.com/reference',
        ])
            ->assertCreated()
            ->assertJsonPath('data.external_url', 'https://example.com/reference');

        $this->assertDatabaseHas('certification_admin_events', [
            'programme_id' => $programme->id,
            'action' => CertificationAdminEventAction::ModuleCreated->value,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('certification_admin_events', [
            'action' => CertificationAdminEventAction::LessonCreated->value,
        ]);
        $this->assertDatabaseHas('certification_admin_events', [
            'action' => CertificationAdminEventAction::ResourceCreated->value,
        ]);
    }

    public function test_published_version_curriculum_is_immutable_and_historical_versions_remain_isolated(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::SuperAdmin)->create();
        Sanctum::actingAs($admin);

        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $v1 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
        ]);
        $module = CertificationModule::factory()->for($v1, 'version')->create([
            'title' => 'V1 Module',
            'sort_order' => 1,
        ]);
        $lesson = CertificationLesson::factory()->for($module, 'module')->create([
            'title' => 'V1 Lesson',
            'sort_order' => 1,
        ]);
        $programme->current_published_version_id = $v1->id;
        $programme->save();

        $baseV1 = $this->base($programme, $v1);

        $this->postJson("{$baseV1}/modules", ['title' => 'Should fail'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->patchJson("{$baseV1}/modules/{$module->id}", ['title' => 'Mutate'])
            ->assertStatus(409);

        $this->postJson("{$baseV1}/modules/{$module->id}/lessons", [
            'title' => 'Should fail',
            'content_type' => 'text',
        ])->assertStatus(409);

        $this->deleteJson("{$baseV1}/modules/{$module->id}/lessons/{$lesson->id}")
            ->assertStatus(409);

        $v2 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->create([
            'version_number' => 2,
            'status' => CertificationProgrammeVersionStatus::Draft,
            'fee_amount_minor' => 2000000,
            'pass_mark_percent' => '80.00',
        ]);
        $baseV2 = $this->base($programme, $v2);

        $this->postJson("{$baseV2}/modules", ['title' => 'V2 Module'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'V2 Module');

        $this->assertDatabaseHas('certification_modules', [
            'programme_version_id' => $v1->id,
            'title' => 'V1 Module',
        ]);
        $this->assertSame(1, CertificationModule::query()->where('programme_version_id', $v1->id)->count());
        $this->assertSame(1, CertificationModule::query()->where('programme_version_id', $v2->id)->count());
    }

    public function test_nested_idor_mismatches_return_not_found(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        [$programmeA, $versionA] = $this->draftStack();
        [$programmeB, $versionB] = $this->draftStack();

        $moduleA = CertificationModule::factory()->for($versionA, 'version')->create(['sort_order' => 1]);
        $moduleB = CertificationModule::factory()->for($versionB, 'version')->create(['sort_order' => 1]);
        $lessonB = CertificationLesson::factory()->for($moduleB, 'module')->create(['sort_order' => 1]);
        $resourceB = CertificationResource::factory()->for($lessonB, 'lesson')->create(['sort_order' => 1]);

        $baseA = $this->base($programmeA, $versionA);

        $this->getJson("{$baseA}/modules/{$moduleB->id}")
            ->assertStatus(404);

        $this->getJson("{$baseA}/modules/{$moduleA->id}/lessons/{$lessonB->id}")
            ->assertStatus(404);

        $this->getJson("{$baseA}/modules/{$moduleA->id}/lessons/{$lessonB->id}/resources/{$resourceB->id}")
            ->assertStatus(404);
    }

    public function test_unpublished_version_curriculum_remains_immutable(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Unpublished,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($programme, 'programme')->create([
            'version_number' => 1,
            'status' => CertificationProgrammeVersionStatus::Unpublished,
            'fee_amount_minor' => 1000000,
            'pass_mark_percent' => '70.00',
        ]);
        $module = CertificationModule::factory()->for($version, 'version')->create(['sort_order' => 1]);

        $this->patchJson($this->base($programme, $version)."/modules/{$module->id}", [
            'title' => 'No',
        ])->assertStatus(409);
    }

    public function test_ambassador_has_no_curriculum_content_endpoints_in_this_slice(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/certification/programmes/1/versions/1/modules')
            ->assertStatus(404);
    }

    public function test_draft_module_delete_renumbers_remaining_modules(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        [$programme, $version] = $this->draftStack();
        $base = $this->base($programme, $version);

        $m1 = $this->postJson("{$base}/modules", ['title' => 'One'])->json('data.id');
        $m2 = $this->postJson("{$base}/modules", ['title' => 'Two'])->json('data.id');
        $this->postJson("{$base}/modules", ['title' => 'Three'])->assertCreated();

        $this->deleteJson("{$base}/modules/{$m1}")->assertOk();

        $this->getJson("{$base}/modules")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $m2)
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.1.sort_order', 2);
    }
}
