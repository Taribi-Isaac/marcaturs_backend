<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use App\Support\Admin\AdminPermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminStaffResource extends JsonResource
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
        $profile = $this->adminStaffProfile;
        $role = $profile?->staff_role;

        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'staff_role' => $role?->value,
            'status' => $this->status->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'permissions' => $role ? AdminPermissionMatrix::valuesForRole($role) : [],
        ];

        if ($this->detailed) {
            $payload['created_by'] = $profile?->createdBy
                ? [
                    'id' => $profile->createdBy->id,
                    'name' => $profile->createdBy->name,
                    'email' => $profile->createdBy->email,
                ]
                : null;
            $payload['events'] = $this->whenLoaded('adminStaffEvents', function () {
                return $this->adminStaffEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'action' => $event->action->value,
                    'previous_staff_role' => $event->previous_staff_role?->value,
                    'new_staff_role' => $event->new_staff_role?->value,
                    'previous_status' => $event->previous_status?->value,
                    'new_status' => $event->new_status?->value,
                    'reason' => $event->reason,
                    'actor' => $event->actor
                        ? [
                            'id' => $event->actor->id,
                            'name' => $event->actor->name,
                            'email' => $event->actor->email,
                        ]
                        : null,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->all();
            });
        }

        return $payload;
    }
}
