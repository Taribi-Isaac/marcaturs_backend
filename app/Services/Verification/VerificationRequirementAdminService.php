<?php

namespace App\Services\Verification;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Models\VerificationRequirement;
use Illuminate\Auth\Access\AuthorizationException;

class VerificationRequirementAdminService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): VerificationRequirement
    {
        $participant = Role::from((string) $attributes['participant_type']);
        $this->assertParticipant($participant);

        $requirement = new VerificationRequirement;
        $requirement->fill($this->only($attributes));
        $requirement->participant_type = $participant;
        $requirement->requirement_type = VerificationRequirementType::from((string) $attributes['requirement_type']);
        $requirement->is_required = (bool) ($attributes['is_required'] ?? true);
        $requirement->is_active = (bool) ($attributes['is_active'] ?? true);
        $requirement->sort_order = (int) ($attributes['sort_order'] ?? 0);
        $requirement->save();

        return $requirement->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(VerificationRequirement $requirement, array $attributes): VerificationRequirement
    {
        if (isset($attributes['participant_type'])) {
            $this->assertParticipant(Role::from((string) $attributes['participant_type']));
        }

        $requirement->fill($this->only($attributes));
        $requirement->save();

        return $requirement->refresh();
    }

    private function assertParticipant(Role $role): void
    {
        if ($role !== Role::Business && $role !== Role::Ambassador) {
            throw new AuthorizationException('Verification requirements apply only to BUSINESS and AMBASSADOR participants.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function only(array $attributes): array
    {
        return collect($attributes)->only([
            'name',
            'description',
            'participant_type',
            'requirement_type',
            'is_required',
            'is_active',
            'sort_order',
            'config',
        ])->all();
    }
}
