<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Models\VerificationRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationRequirement>
 */
class VerificationRequirementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Legal business name',
            'description' => 'Provide the registered legal name of the business.',
            'participant_type' => Role::Business,
            'requirement_type' => VerificationRequirementType::Text,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
            'config' => null,
        ];
    }

    public function ambassador(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Full legal name',
            'description' => 'Provide the name that matches your identity evidence.',
            'participant_type' => Role::Ambassador,
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Identity evidence',
            'description' => 'Upload a supporting identity document.',
            'requirement_type' => VerificationRequirementType::Document,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Contact email',
            'requirement_type' => VerificationRequirementType::Email,
        ]);
    }

    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
