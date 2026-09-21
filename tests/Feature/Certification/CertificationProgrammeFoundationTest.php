<?php

namespace Tests\Feature\Certification;

use App\Enums\AdminStaffRole;
use App\Enums\CertificationAdminEventAction;
use App\Enums\CertificationProgrammeStatus;
use App\Enums\CertificationProgrammeVersionStatus;
use App\Models\CertificationAdminEvent;
use App\Models\CertificationProgramme;
use App\Models\CertificationProgrammeVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificationProgrammeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_admin_and_catalogue_access_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/certification/programmes')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        $this->getJson('/api/v1/certification/programmes')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_ambassador_cannot_manage_programmes(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->postJson('/api/v1/admin/certification/programmes', [
            'name' => 'Should fail',
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_verification_staff_cannot_view_or_manage_certification(): void
    {
        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Verification)->create());

        $this->getJson('/api/v1/admin/certification/programmes')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->postJson('/api/v1/admin/certification/programmes', [
            'name' => 'Should fail',
        ])->assertStatus(403);
    }

    public function test_moderation_staff_cannot_manage_certification(): void
    {
        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::Moderation)->create());

        $this->getJson('/api/v1/admin/certification/programmes')
            ->assertStatus(403);
    }

    public function test_operations_can_create_programme_and_version_lifecycle(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/certification/programmes', [
            'name' => 'Ambassador Professional Certification',
            'description' => 'Professional development programme.',
            'learning_objectives' => 'Learn marketplace fundamentals.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ambassador Professional Certification')
            ->assertJsonPath('data.status', CertificationProgrammeStatus::Draft->value)
            ->assertJsonPath('data.current_published_version_id', null);

        $programmeId = (int) $this->getJson('/api/v1/admin/certification/programmes')->json('data.0.id');

        $this->assertDatabaseHas('certification_admin_events', [
            'programme_id' => $programmeId,
            'action' => CertificationAdminEventAction::ProgrammeCreated->value,
            'actor_user_id' => $admin->id,
        ]);

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions", [])
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', CertificationProgrammeVersionStatus::Draft->value)
            ->assertJsonPath('data.fee_amount_minor', null)
            ->assertJsonPath('data.pass_mark_percent', null);

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/1/publish")
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->patchJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/1", [
            'fee_amount_minor' => 1500000,
            'fee_currency' => 'ngn',
            'pass_mark_percent' => 75,
        ])
            ->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 1500000)
            ->assertJsonPath('data.fee_currency', 'NGN')
            ->assertJsonPath('data.pass_mark_percent', '75.00');

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/1/publish")
            ->assertOk()
            ->assertJsonPath('data.status', CertificationProgrammeVersionStatus::Published->value);

        $this->getJson("/api/v1/admin/certification/programmes/{$programmeId}")
            ->assertOk()
            ->assertJsonPath('data.status', CertificationProgrammeStatus::Published->value)
            ->assertJsonPath('data.current_published_version.version_number', 1);

        $this->assertDatabaseHas('certification_admin_events', [
            'programme_id' => $programmeId,
            'action' => CertificationAdminEventAction::VersionPublished->value,
        ]);

        $this->patchJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/1", [
            'fee_amount_minor' => 999,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions", [
            'fee_amount_minor' => 2000000,
            'pass_mark_percent' => 80,
        ])
            ->assertCreated()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.status', CertificationProgrammeVersionStatus::Draft->value);

        $this->getJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/1")
            ->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 1500000);

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/2/publish")
            ->assertOk()
            ->assertJsonPath('data.version_number', 2);

        $this->getJson("/api/v1/admin/certification/programmes/{$programmeId}")
            ->assertOk()
            ->assertJsonPath('data.current_published_version.version_number', 2);

        $this->postJson("/api/v1/admin/certification/programmes/{$programmeId}/versions/2/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', CertificationProgrammeVersionStatus::Unpublished->value);

        $this->getJson("/api/v1/admin/certification/programmes/{$programmeId}")
            ->assertOk()
            ->assertJsonPath('data.status', CertificationProgrammeStatus::Unpublished->value)
            ->assertJsonPath('data.current_published_version_id', null);

        $this->patchJson("/api/v1/admin/certification/programmes/{$programmeId}", [
            'status' => CertificationProgrammeStatus::Archived->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', CertificationProgrammeStatus::Archived->value);

        $this->assertDatabaseHas('certification_admin_events', [
            'programme_id' => $programmeId,
            'action' => CertificationAdminEventAction::ProgrammeArchived->value,
        ]);
    }

    public function test_super_admin_can_view_programmes(): void
    {
        Sanctum::actingAs(User::factory()->adminStaff(AdminStaffRole::SuperAdmin)->create());
        CertificationProgramme::factory()->create();

        $this->getJson('/api/v1/admin/certification/programmes')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_version_numbers_are_rejected_by_the_database(): void
    {
        $programme = CertificationProgramme::factory()->create();
        CertificationProgrammeVersion::factory()->for($programme, 'programme')->create(['version_number' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        CertificationProgrammeVersion::factory()->for($programme, 'programme')->create(['version_number' => 1]);
    }

    public function test_only_one_draft_version_is_allowed(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $programme = CertificationProgramme::factory()->create();
        CertificationProgrammeVersion::factory()->for($programme, 'programme')->create(['version_number' => 1]);

        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions", [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_version_idor_mismatch_returns_not_found(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $first = CertificationProgramme::factory()->create();
        $second = CertificationProgramme::factory()->create();
        CertificationProgrammeVersion::factory()->for($first, 'programme')->create(['version_number' => 1]);
        CertificationProgrammeVersion::factory()->for($second, 'programme')->create(['version_number' => 1]);

        $this->getJson("/api/v1/admin/certification/programmes/{$first->id}/versions/1")
            ->assertOk()
            ->assertJsonPath('data.programme_id', $first->id);

        // Scoped binding: version 99 does not exist under first programme.
        $this->getJson("/api/v1/admin/certification/programmes/{$first->id}/versions/99")
            ->assertStatus(404);
    }

    public function test_ambassador_catalogue_hides_draft_and_unpublished_and_excludes_pass_mark(): void
    {
        $draft = CertificationProgramme::factory()->create([
            'name' => 'Draft Programme',
            'status' => CertificationProgrammeStatus::Draft,
        ]);
        CertificationProgrammeVersion::factory()->for($draft, 'programme')->create();

        $published = CertificationProgramme::factory()->create([
            'name' => 'Published Programme',
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $version = CertificationProgrammeVersion::factory()->for($published, 'programme')->published()->create([
            'fee_amount_minor' => 1500000,
            'pass_mark_percent' => '70.00',
        ]);
        $published->current_published_version_id = $version->id;
        $published->save();

        $unpublished = CertificationProgramme::factory()->create([
            'name' => 'Unpublished Programme',
            'status' => CertificationProgrammeStatus::Unpublished,
        ]);
        CertificationProgrammeVersion::factory()->for($unpublished, 'programme')->create([
            'status' => CertificationProgrammeVersionStatus::Unpublished,
            'version_number' => 1,
            'fee_amount_minor' => 100,
            'pass_mark_percent' => '50.00',
        ]);

        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/certification/programmes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Published Programme')
            ->assertJsonPath('data.0.current_published_version.fee_amount_minor', 1500000)
            ->assertJsonMissingPath('data.0.current_published_version.pass_mark_percent');

        $this->getJson("/api/v1/certification/programmes/{$published->id}")
            ->assertOk()
            ->assertJsonPath('data.current_published_version.fee_currency', 'NGN')
            ->assertJsonMissingPath('data.pass_mark_percent');

        $this->getJson("/api/v1/certification/programmes/{$draft->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->getJson("/api/v1/certification/programmes/{$unpublished->id}")
            ->assertStatus(404);

        Sanctum::actingAs(User::factory()->business()->create());
        $this->getJson('/api/v1/certification/programmes')
            ->assertStatus(403);
    }

    public function test_historical_published_version_remains_unchanged_after_new_publish(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::SuperAdmin)->create();
        Sanctum::actingAs($admin);

        $programme = CertificationProgramme::factory()->create();
        $v1 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->publishable()->create([
            'version_number' => 1,
            'fee_amount_minor' => 1000000,
            'pass_mark_percent' => '60.00',
        ]);

        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions/1/publish")->assertOk();

        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions", [
            'fee_amount_minor' => 2000000,
            'pass_mark_percent' => 90,
        ])->assertCreated();

        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions/2/publish")->assertOk();

        $v1->refresh();
        $this->assertSame(CertificationProgrammeVersionStatus::Published, $v1->status);
        $this->assertSame(1000000, $v1->fee_amount_minor);
        $this->assertSame('60.00', (string) $v1->pass_mark_percent);

        $programme->refresh();
        $this->assertSame(2, $programme->currentPublishedVersion?->version_number);
    }

    public function test_cannot_unpublish_non_current_published_version(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);

        $programme = CertificationProgramme::factory()->create([
            'status' => CertificationProgrammeStatus::Published,
        ]);
        $v1 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 1,
            'fee_amount_minor' => 1000000,
        ]);
        $v2 = CertificationProgrammeVersion::factory()->for($programme, 'programme')->published()->create([
            'version_number' => 2,
            'fee_amount_minor' => 2000000,
            'pass_mark_percent' => '80.00',
        ]);
        $programme->current_published_version_id = $v2->id;
        $programme->save();

        $this->postJson("/api/v1/admin/certification/programmes/{$programme->id}/versions/1/unpublish")
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->assertSame(CertificationProgrammeVersionStatus::Published, $v1->fresh()->status);
    }

    public function test_audit_events_are_recorded_for_version_updates(): void
    {
        $admin = User::factory()->adminStaff(AdminStaffRole::Operations)->create();
        Sanctum::actingAs($admin);
        $programme = CertificationProgramme::factory()->create();
        CertificationProgrammeVersion::factory()->for($programme, 'programme')->create();

        $this->patchJson("/api/v1/admin/certification/programmes/{$programme->id}/versions/1", [
            'fee_amount_minor' => 500000,
            'pass_mark_percent' => 55,
        ])->assertOk();

        $this->assertTrue(
            CertificationAdminEvent::query()
                ->where('programme_id', $programme->id)
                ->where('action', CertificationAdminEventAction::VersionUpdated->value)
                ->exists(),
        );
    }
}
