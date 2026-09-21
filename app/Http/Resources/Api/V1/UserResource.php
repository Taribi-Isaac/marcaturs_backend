<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Admin\AdminAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($this->isAdmin()) {
            /** @var User $user */
            $user = $this->resource;
            $authorization = app(AdminAuthorization::class);
            $payload['staff_role'] = $authorization->staffRole($user)?->value;
            $payload['permissions'] = $authorization->permissionValues($user);
        }

        return $payload;
    }
}
