<?php

namespace Tests\Unit;

use App\Enums\OverallVerificationStatus;
use App\Enums\VerificationSubmissionStatus;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Services\Verification\VerificationStatusCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationStatusCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_required_requirements_is_not_started_not_verified(): void
    {
        $user = User::factory()->business()->create();
        VerificationRequirement::factory()->optional()->create();

        $this->assertSame(
            OverallVerificationStatus::NotStarted,
            app(VerificationStatusCalculator::class)->overall($user),
        );
    }

    public function test_missing_required_submissions_stay_not_started_or_pending(): void
    {
        $user = User::factory()->business()->create();
        $first = VerificationRequirement::factory()->create(['sort_order' => 1]);
        $second = VerificationRequirement::factory()->create(['name' => 'Trading name', 'sort_order' => 2]);

        $calculator = app(VerificationStatusCalculator::class);

        $this->assertSame(OverallVerificationStatus::NotStarted, $calculator->overall($user));

        VerificationSubmission::factory()->for($user)->for($first, 'requirement')->create();

        $this->assertSame(OverallVerificationStatus::Pending, $calculator->overall($user));

        VerificationSubmission::factory()->for($user)->for($second, 'requirement')->approved()->create();

        $this->assertSame(OverallVerificationStatus::Pending, $calculator->overall($user));
    }

    public function test_overall_status_follows_required_submission_states(): void
    {
        $user = User::factory()->business()->create();
        $requirement = VerificationRequirement::factory()->create();
        $submission = VerificationSubmission::factory()->for($user)->for($requirement, 'requirement')->create();
        $calculator = app(VerificationStatusCalculator::class);

        $this->assertSame(OverallVerificationStatus::Pending, $calculator->overall($user));

        $submission->update(['status' => VerificationSubmissionStatus::UnderReview]);
        $this->assertSame(OverallVerificationStatus::UnderReview, $calculator->overall($user->fresh()));

        $submission->update(['status' => VerificationSubmissionStatus::Rejected, 'review_reason' => 'Unclear']);
        $this->assertSame(OverallVerificationStatus::Rejected, $calculator->overall($user->fresh()));

        $submission->update(['status' => VerificationSubmissionStatus::MoreInformationRequired]);
        $this->assertSame(OverallVerificationStatus::MoreInformationRequired, $calculator->overall($user->fresh()));

        $submission->update(['status' => VerificationSubmissionStatus::Approved]);
        $this->assertSame(OverallVerificationStatus::Verified, $calculator->overall($user->fresh()));
    }

    public function test_optional_requirements_do_not_block_verified(): void
    {
        $user = User::factory()->business()->create();
        $required = VerificationRequirement::factory()->create();
        VerificationRequirement::factory()->optional()->create(['name' => 'Optional licence']);
        VerificationSubmission::factory()->for($user)->for($required, 'requirement')->approved()->create();

        $this->assertSame(
            OverallVerificationStatus::Verified,
            app(VerificationStatusCalculator::class)->overall($user),
        );
    }

    public function test_one_unresolved_required_requirement_is_not_verified(): void
    {
        $user = User::factory()->business()->create();
        $approved = VerificationRequirement::factory()->create(['name' => 'Legal name']);
        $pending = VerificationRequirement::factory()->create(['name' => 'Address evidence']);

        VerificationSubmission::factory()->for($user)->for($approved, 'requirement')->approved()->create();
        VerificationSubmission::factory()->for($user)->for($pending, 'requirement')->create();

        $this->assertSame(
            OverallVerificationStatus::Pending,
            app(VerificationStatusCalculator::class)->overall($user),
        );
    }
}
