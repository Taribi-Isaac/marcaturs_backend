<?php

namespace App\Services\Deals;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CommissionQueryService
{
    public function listForParticipant(User $user, int $perPage): LengthAwarePaginator
    {
        $this->assertParticipantRole($user);

        $query = Commission::query()->with(['deal', 'business', 'ambassador']);

        if ($user->isAmbassador()) {
            $query->where('ambassador_user_id', $user->id);
        } else {
            $query->where('business_user_id', $user->id);
        }

        return $query
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function showForParticipant(User $user, Commission $commission): Commission
    {
        $this->assertParticipantRole($user);

        if ($user->isAmbassador() && $commission->ambassador_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        if ($user->isBusiness() && $commission->business_user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        return $commission->loadMissing(['deal', 'business', 'ambassador']);
    }

    private function assertParticipantRole(User $user): void
    {
        if (! $user->isBusiness() && ! $user->isAmbassador()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
