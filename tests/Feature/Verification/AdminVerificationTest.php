<?php

namespace Tests\Feature\Verification;

use App\Enums\OverallVerificationStatus;
use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Enums\VerificationSubmissionStatus;
use App\Models\AmbassadorProfile;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_cannot_access_admin_verification_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/admin/verification/requirements')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->getJson('/api/v1/admin/verification/submissions')
            ->assertStatus(403);
    }

    public function test_admin_can_create_and_deactivate_requirements(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/verification/requirements', [
            'name' => 'Registered office address',
            'description' => 'Provide the address used for correspondence.',
            'participant_type' => Role::Business->value,
            'requirement_type' => VerificationRequirementType::Text->value,
            'is_required' => true,
            'sort_order' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Registered office address')
            ->assertJsonPath('data.participant_type', 'BUSINESS')
            ->assertJsonPath('data.is_active', true);

        $requirement = VerificationRequirement::query()->firstOrFail();

        $this->patchJson('/api/v1/admin/verification/requirements/'.$requirement->id, [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/v1/admin/verification/requirements', [
            'name' => 'Admin check',
            'participant_type' => Role::Admin->value,
            'requirement_type' => VerificationRequirementType::Text->value,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_admin_review_workflow_and_audit_history(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->create();
        $submission = VerificationSubmission::factory()->for($user)->for($requirement, 'requirement')->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/verification/submissions?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $submission->id)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonMissingPath('data.0.user.password')
            ->assertJsonMissingPath('data.0.evidence.0.path');

        $this->postJson('/api/v1/admin/verification/submissions/'.$submission->id.'/start-review')
            ->assertOk()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::UnderReview->value);

        $this->postJson('/api/v1/admin/verification/submissions/'.$submission->id.'/approve', [
            'notes' => 'Matches the completed profile.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::Approved->value)
            ->assertJsonPath('data.reviewer_notes', 'Matches the completed profile.');

        $this->getJson('/api/v1/admin/verification/submissions/'.$submission->id.'/events')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'started_review')
            ->assertJsonPath('data.1.action', 'approved')
            ->assertJsonPath('data.1.reviewer_notes', 'Matches the completed profile.');

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/verification/status')
            ->assertOk()
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::Verified->value)
            ->assertJsonMissingPath('data.requirements.0.submission.reviewer_notes');
    }

    public function test_reject_and_request_information_require_reasons_and_block_invalid_transitions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->business()->create();
        $requirement = VerificationRequirement::factory()->create();
        $submission = VerificationSubmission::factory()->for($user)->for($requirement, 'requirement')->underReview()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/verification/submissions/'.$submission->id.'/reject', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->postJson('/api/v1/admin/verification/submissions/'.$submission->id.'/reject', [
            'reason' => 'The uploaded copy is unreadable.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::Rejected->value)
            ->assertJsonPath('data.review_reason', 'The uploaded copy is unreadable.');

        $this->postJson('/api/v1/admin/verification/submissions/'.$submission->id.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $otherRequirement = VerificationRequirement::factory()->create(['name' => 'Address evidence', 'sort_order' => 2]);
        $otherSubmission = VerificationSubmission::factory()
            ->for($user)
            ->for($otherRequirement, 'requirement')
            ->underReview()
            ->create();

        $this->postJson('/api/v1/admin/verification/submissions/'.$otherSubmission->id.'/request-information', [
            'reason' => 'Provide a wider crop of the same document.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::MoreInformationRequired->value);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/verification/status')
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::MoreInformationRequired->value)
            ->assertJsonPath('data.requirements.1.submission.review_reason', 'Provide a wider crop of the same document.');
    }

    public function test_approving_one_required_requirement_does_not_verify_the_participant(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->business()->create();
        $first = VerificationRequirement::factory()->create(['name' => 'Legal name']);
        $second = VerificationRequirement::factory()->create(['name' => 'Operating address']);
        $firstSubmission = VerificationSubmission::factory()->for($user)->for($first, 'requirement')->underReview()->create();
        VerificationSubmission::factory()->for($user)->for($second, 'requirement')->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/verification/submissions/'.$firstSubmission->id.'/approve')
            ->assertOk();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/verification/status')
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::Pending->value);
    }

    public function test_admin_can_download_evidence_and_participants_cannot(): void
    {
        Storage::fake('sensitive');

        $admin = User::factory()->admin()->create();
        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->ambassador()->document()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'evidence' => UploadedFile::fake()->image('evidence.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $evidence = VerificationEvidence::query()->firstOrFail();
        $submission = $evidence->submission;

        $this->get('/api/v1/admin/verification/submissions/'.$submission->id.'/evidence/'.$evidence->id.'/download')
            ->assertStatus(403);

        Sanctum::actingAs($admin);
        $download = $this->get('/api/v1/admin/verification/submissions/'.$submission->id.'/evidence/'.$evidence->id.'/download');
        $download->assertOk();
        $this->assertStringContainsString('image/png', (string) $download->headers->get('content-type'));

        $otherSubmission = VerificationSubmission::factory()->create();
        $this->get('/api/v1/admin/verification/submissions/'.$otherSubmission->id.'/evidence/'.$evidence->id.'/download')
            ->assertNotFound();
    }

    public function test_unauthenticated_admin_routes_return_401(): void
    {
        $this->getJson('/api/v1/admin/verification/submissions')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }
}
