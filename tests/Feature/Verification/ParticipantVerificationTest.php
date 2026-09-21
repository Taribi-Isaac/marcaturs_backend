<?php

namespace Tests\Feature\Verification;

use App\Enums\OverallVerificationStatus;
use App\Enums\VerificationSubmissionStatus;
use App\Models\AmbassadorProfile;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParticipantVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/verification/requirements')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_admin_cannot_use_participant_verification_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/verification/requirements')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_restricted_users_cannot_access_verification(): void
    {
        Sanctum::actingAs(User::factory()->business()->restricted()->create());

        $this->getJson('/api/v1/verification/requirements')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_business_sees_only_active_business_requirements(): void
    {
        VerificationRequirement::factory()->create(['name' => 'Legal business name', 'sort_order' => 1]);
        VerificationRequirement::factory()->optional()->create(['name' => 'Optional website', 'sort_order' => 2]);
        VerificationRequirement::factory()->inactive()->create(['name' => 'Inactive requirement']);
        VerificationRequirement::factory()->ambassador()->create();

        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/verification/requirements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Legal business name')
            ->assertJsonPath('data.0.is_required', true)
            ->assertJsonPath('data.1.name', 'Optional website')
            ->assertJsonPath('data.1.is_required', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'Inactive requirement']);
    }

    public function test_ambassador_sees_only_active_ambassador_requirements(): void
    {
        VerificationRequirement::factory()->create();
        VerificationRequirement::factory()->ambassador()->create(['name' => 'Full legal name']);

        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/verification/requirements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Full legal name')
            ->assertJsonPath('data.0.participant_type', 'AMBASSADOR');
    }

    public function test_status_starts_not_started_and_excludes_sensitive_storage_fields(): void
    {
        VerificationRequirement::factory()->create();
        Sanctum::actingAs(User::factory()->business()->create());

        $this->getJson('/api/v1/verification/status')
            ->assertOk()
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::NotStarted->value)
            ->assertJsonPath('data.requirements.0.submission', null)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.requirements.0.requirement.config');
    }

    public function test_submission_requires_a_completed_profile(): void
    {
        $requirement = VerificationRequirement::factory()->create();
        Sanctum::actingAs(User::factory()->business()->create());

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'text_value' => 'Ada Ventures Ltd',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);
    }

    public function test_business_can_submit_text_and_cannot_submit_twice(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'text_value' => 'Ada Ventures Ltd',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::Pending->value)
            ->assertJsonPath('data.text_value', 'Ada Ventures Ltd')
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonMissingPath('data.reviewer_notes')
            ->assertJsonMissingPath('data.evidence.0.path')
            ->assertJsonMissingPath('data.evidence.0.disk');

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'text_value' => 'Ada Ventures Ltd',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->getJson('/api/v1/verification/status')
            ->assertOk()
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::Pending->value);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->email()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'text_value' => 'not-an-email',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_document_requirement_stores_private_evidence(): void
    {
        Storage::fake('sensitive');

        $user = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->ambassador()->document()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('evidence.png');

        $response = $this->post('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'evidence' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.evidence.0.original_filename', 'evidence.png')
            ->assertJsonMissingPath('data.evidence.0.path')
            ->assertJsonMissingPath('data.evidence.0.disk');

        $this->assertNotEmpty(Storage::disk('sensitive')->allFiles());
        $this->assertStringStartsWith('verification/', Storage::disk('sensitive')->allFiles()[0]);
    }

    public function test_user_cannot_resubmit_another_participants_submission(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $requirement = VerificationRequirement::factory()->create();
        $submission = VerificationSubmission::factory()->for($owner)->for($requirement, 'requirement')->rejected()->create();

        $other = User::factory()->business()->create();
        BusinessProfile::factory()->for($other)->create();
        Sanctum::actingAs($other);

        $this->patchJson('/api/v1/verification/submissions/'.$submission->id, [
            'text_value' => 'Stolen update',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->assertSame('Example submitted value', $submission->fresh()->text_value);
    }

    public function test_resubmission_is_allowed_after_rejection_and_preserves_history(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $requirement->id,
            'text_value' => 'First value',
        ])->assertCreated();

        $submission = VerificationSubmission::query()->firstOrFail();
        $submission->update([
            'status' => VerificationSubmissionStatus::Rejected,
            'review_reason' => 'Does not match the profile.',
        ]);

        $this->patchJson('/api/v1/verification/submissions/'.$submission->id, [
            'text_value' => 'Corrected value',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', VerificationSubmissionStatus::Pending->value)
            ->assertJsonPath('data.text_value', 'Corrected value')
            ->assertJsonPath('data.current_version', 2);

        $this->assertDatabaseCount('verification_submission_versions', 2);
        $this->assertDatabaseHas('verification_submission_versions', [
            'verification_submission_id' => $submission->id,
            'version' => 1,
            'text_value' => 'First value',
        ]);
        $this->assertDatabaseHas('verification_review_events', [
            'verification_submission_id' => $submission->id,
            'action' => 'resubmitted',
        ]);
    }

    public function test_pending_submissions_cannot_be_resubmitted(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();
        $requirement = VerificationRequirement::factory()->create();
        $submission = VerificationSubmission::factory()->for($user)->for($requirement, 'requirement')->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/verification/submissions/'.$submission->id, [
            'text_value' => 'Too soon',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);
    }

    public function test_empty_active_requirements_return_not_started_without_error(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->getJson('/api/v1/verification/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.overall_status', OverallVerificationStatus::NotStarted->value)
            ->assertJsonPath('data.requirements', [])
            ->assertJsonMissingPath('error');

        $this->getJson('/api/v1/verification/requirements')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_suspended_and_banned_users_cannot_access_verification(): void
    {
        Sanctum::actingAs(User::factory()->ambassador()->suspended()->create());
        $this->getJson('/api/v1/verification/status')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN)
            ->assertJsonPath('error.message', 'This account is not permitted to access the platform.');

        Sanctum::actingAs(User::factory()->ambassador()->banned()->create());
        $this->getJson('/api/v1/verification/requirements')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
    }

    public function test_ambassador_cannot_submit_business_requirement(): void
    {
        $businessRequirement = VerificationRequirement::factory()->create();
        $ambassador = User::factory()->ambassador()->create();
        AmbassadorProfile::factory()->for($ambassador)->create();
        Sanctum::actingAs($ambassador);

        $this->postJson('/api/v1/verification/submissions', [
            'requirement_id' => $businessRequirement->id,
            'text_value' => 'Wrong role payload',
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND)
            ->assertJsonPath('error.message', 'The requested resource was not found.');
    }

    public function test_participant_cannot_read_another_users_submission_via_status(): void
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $requirement = VerificationRequirement::factory()->create();
        VerificationSubmission::factory()
            ->for($owner)
            ->for($requirement, 'requirement')
            ->create(['text_value' => 'Owner secret response']);

        $other = User::factory()->business()->create();
        Sanctum::actingAs($other);

        $response = $this->getJson('/api/v1/verification/status')->assertOk();
        $payload = json_encode($response->json());
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('Owner secret response', $payload);
        $this->assertNull(data_get($response->json(), 'data.requirements.0.submission'));
    }
}
