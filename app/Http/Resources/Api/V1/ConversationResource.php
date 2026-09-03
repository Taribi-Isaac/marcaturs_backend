<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $includeReportDetails = $viewer?->isAdmin() === true;

        return [
            'id' => $this->id,
            'counterpart' => $this->when(! $includeReportDetails, $this->counterpartPayload($viewer)),
            'business' => $this->when($includeReportDetails, $this->userPayload($this->business)),
            'ambassador' => $this->when($includeReportDetails, $this->userPayload($this->ambassador)),
            'reported' => $this->isReported(),
            'reported_at' => $this->when($includeReportDetails, $this->reported_at?->toIso8601String()),
            'report_reason' => $this->when($includeReportDetails, $this->report_reason),
            'reported_by' => $this->when($includeReportDetails, $this->reporterPayload()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function counterpartPayload(?User $viewer): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $counterpart = $viewer->isAmbassador()
            ? $this->business
            : $this->ambassador;

        return $this->userPayload($counterpart);
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
            'name' => $user->name,
            'role' => $user->role->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reporterPayload(): ?array
    {
        if ($this->reporter === null) {
            return null;
        }

        return [
            'id' => $this->reporter->id,
            'role' => $this->reporter->role->value,
        ];
    }
}
