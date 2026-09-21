<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\AdminStaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminStaffInvitation
 */
class AdminStaffInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'staff_role' => $this->staff_role->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invited_by_user_id' => $this->invited_by_user_id,
        ];
    }
}
