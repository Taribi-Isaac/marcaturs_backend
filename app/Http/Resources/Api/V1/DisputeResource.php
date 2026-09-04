<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispute
 */
class DisputeResource extends JsonResource
{
    public function __construct($resource, private readonly bool $forAdmin = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $showResolution = $this->forAdmin
            || in_array($this->status, [DisputeStatus::Resolved, DisputeStatus::Closed], true);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'deal_id' => $this->deal_id,
            'commission_id' => $this->commission_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),
            'reporter' => $this->userPayload($this->reporter),
            'accused' => $this->userPayload($this->accused),
            'description' => $this->description,
            'decision_notes' => $showResolution ? $this->decision_notes : null,
            'action_notes' => $showResolution ? $this->action_notes : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', fn () => DisputeAttachmentResource::collection($this->attachments)->resolve($request)),
            'events' => $this->whenLoaded('events', function () use ($request) {
                $events = $this->forAdmin
                    ? $this->events
                    : $this->events;

                return DisputeEventResource::collection($events)->resolve($request);
            }),
            'deal' => $this->when($this->forAdmin && $this->relationLoaded('deal'), function () use ($request) {
                return (new DealResource($this->deal))->resolve($request);
            }),
            'commission' => $this->when($this->forAdmin && $this->relationLoaded('commission') && $this->commission !== null, function () use ($request) {
                return (new CommissionResource($this->commission))->resolve($request);
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userPayload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'role' => $user->role->value,
        ];
    }
}
