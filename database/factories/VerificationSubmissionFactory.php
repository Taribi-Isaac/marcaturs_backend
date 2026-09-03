<?php

namespace Database\Factories;

use App\Enums\VerificationSubmissionStatus;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationSubmission>
 */
class VerificationSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->business(),
            'verification_requirement_id' => VerificationRequirement::factory(),
            'status' => VerificationSubmissionStatus::Pending,
            'text_value' => 'Example submitted value',
            'current_version' => 1,
            'submitted_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationSubmissionStatus::Pending,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationSubmissionStatus::UnderReview,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationSubmissionStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationSubmissionStatus::Rejected,
            'review_reason' => 'Evidence is insufficient.',
            'reviewed_at' => now(),
        ]);
    }

    public function moreInformationRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VerificationSubmissionStatus::MoreInformationRequired,
            'review_reason' => 'Please provide a clearer copy.',
            'reviewed_at' => now(),
        ]);
    }
}
