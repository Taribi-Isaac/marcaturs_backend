<?php

namespace App\Services\Campaigns;

use App\Models\CampaignExtensionPackage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class CampaignExtensionPackageAdminService
{
    /**
     * @return Collection<int, CampaignExtensionPackage>
     */
    public function all(User $admin): Collection
    {
        $this->assertAdmin($admin);

        return CampaignExtensionPackage::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $admin, array $attributes): CampaignExtensionPackage
    {
        $this->assertAdmin($admin);

        $package = new CampaignExtensionPackage;
        $package->name = (string) $attributes['name'];
        $package->duration_days = (int) $attributes['duration_days'];
        $package->amount_minor = (int) $attributes['amount_minor'];
        $package->currency = strtoupper((string) ($attributes['currency'] ?? 'NGN'));
        $package->is_active = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : true;
        $package->sort_order = (int) ($attributes['sort_order'] ?? 0);
        $package->save();

        return $package->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $admin, CampaignExtensionPackage $package, array $attributes): CampaignExtensionPackage
    {
        $this->assertAdmin($admin);

        if (array_key_exists('name', $attributes)) {
            $package->name = (string) $attributes['name'];
        }
        if (array_key_exists('duration_days', $attributes)) {
            $package->duration_days = (int) $attributes['duration_days'];
        }
        if (array_key_exists('amount_minor', $attributes)) {
            $package->amount_minor = (int) $attributes['amount_minor'];
        }
        if (array_key_exists('currency', $attributes)) {
            $package->currency = strtoupper((string) $attributes['currency']);
        }
        if (array_key_exists('is_active', $attributes)) {
            $package->is_active = (bool) $attributes['is_active'];
        }
        if (array_key_exists('sort_order', $attributes)) {
            $package->sort_order = (int) $attributes['sort_order'];
        }

        $package->save();

        return $package->fresh();
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
