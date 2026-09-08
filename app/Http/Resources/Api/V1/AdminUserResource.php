<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OverallVerificationStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = $this->admin_verification_status;
        $counts = $this->admin_relationship_counts ?? [
            'campaigns' => 0,
            'deals' => 0,
            'open_disputes' => 0,
            'commissions_due' => 0,
            'commissions_overdue' => 0,
        ];

        $payload = [
            'id' => $this->id,
            'role' => $this->role->value,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'profile_summary' => $this->profileSummary(),
            'verification_status' => ($verification instanceof OverallVerificationStatus
                ? $verification
                : OverallVerificationStatus::NotStarted)->value,
            'counts' => $counts,
        ];

        if ($this->detailed) {
            $payload['last_login_at'] = $this->last_login_at?->toIso8601String();
            $payload['profile'] = $this->fullProfile($request);
            $payload['verification_submissions'] = $this->admin_verification_submissions ?? [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileSummary(): ?array
    {
        if ($this->role === Role::Business) {
            $profile = $this->businessProfile;

            return $profile === null ? null : [
                'legal_name' => $profile->legal_name,
                'trading_name' => $profile->trading_name,
            ];
        }

        if ($this->role === Role::Ambassador) {
            $profile = $this->ambassadorProfile;

            return $profile === null ? null : [
                'display_name' => $profile->display_name,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fullProfile(Request $request): ?array
    {
        if ($this->role === Role::Business && $this->businessProfile !== null) {
            return (new BusinessProfileResource($this->businessProfile))->resolve($request);
        }

        if ($this->role === Role::Ambassador && $this->ambassadorProfile !== null) {
            return (new AmbassadorProfileResource($this->ambassadorProfile))->resolve($request);
        }

        return null;
    }
}
