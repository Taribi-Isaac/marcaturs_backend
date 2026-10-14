<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\RegistrationApproval;
use App\Models\RegistrationApprovalEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegistrationApproval
 */
class RegistrationApprovalResource extends JsonResource
{
    public function __construct(
        RegistrationApproval $resource,
        private readonly bool $detailed = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'note' => $this->note,
            'created_by' => $this->actorSummary($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toIso8601String(),
            'revoked_by' => $this->actorSummary($this->whenLoaded('revokedBy')),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'consumed_by_user_id' => $this->consumed_by_user_id,
            'consumed_at' => $this->consumed_at?->toIso8601String(),
        ];

        if ($this->detailed && $this->relationLoaded('events')) {
            $data['events'] = $this->events->map(fn (RegistrationApprovalEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action->value,
                'previous_status' => $event->previous_status?->value,
                'new_status' => $event->new_status?->value,
                'reason' => $event->reason,
                'actor' => $this->actorSummary($event->relationLoaded('actor') ? $event->actor : null),
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actorSummary(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }
}
