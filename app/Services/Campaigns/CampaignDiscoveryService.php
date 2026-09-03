<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\OverallVerificationStatus;
use App\Enums\Role;
use App\Enums\VerificationSubmissionStatus;
use App\Http\Requests\Api\V1\Marketplace\MarketplaceCampaignIndexRequest;
use App\Models\Campaign;
use App\Models\VerificationRequirement;
use App\Services\Verification\VerificationStatusCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CampaignDiscoveryService
{
    public function __construct(
        private readonly VerificationStatusCalculator $verification,
    ) {}

    public function index(MarketplaceCampaignIndexRequest $request): LengthAwarePaginator
    {
        $query = Campaign::query()
            ->discoverable()
            ->with(['category', 'currentVersion', 'user.businessProfile']);

        $this->applyFilters($query, $request);

        $perPage = min(
            max(1, (int) $request->input('per_page', config('api.pagination.default_per_page'))),
            (int) config('api.pagination.max_per_page'),
        );

        return $query
            ->orderByDesc('listing_starts_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(int $campaignId): Campaign
    {
        $campaign = Campaign::query()
            ->discoverable()
            ->with(['category', 'currentVersion', 'user.businessProfile', 'marketingResources'])
            ->whereKey($campaignId)
            ->first();

        if ($campaign === null) {
            throw new ModelNotFoundException;
        }

        $campaign->marketingResources->each(
            fn ($resource) => $resource->setRelation('campaign', $campaign),
        );

        return $campaign;
    }

    /**
     * @param  iterable<int, Campaign>  $campaigns
     * @return array<int, OverallVerificationStatus>
     */
    public function verificationStatuses(iterable $campaigns): array
    {
        $users = collect($campaigns)
            ->map(fn (Campaign $campaign) => $campaign->user)
            ->filter()
            ->unique('id')
            ->values();

        return $this->verification->overallMany($users);
    }

    private function applyFilters(Builder $query, MarketplaceCampaignIndexRequest $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->integer('category_id'));
        }

        if ($request->filled('commission_type')) {
            $query->whereHas('currentVersion', function (Builder $version) use ($request): void {
                $version->where('commission_type', $request->string('commission_type')->toString());
            });
        }

        if ($request->filled('service_area')) {
            $area = $this->like($request->string('service_area')->toString());
            $query->whereHas('currentVersion', function (Builder $version) use ($area): void {
                $version->where('service_area', 'like', $area);
            });
        }

        if ($request->filled('price_min') || $request->filled('price_max')) {
            $query->whereHas('currentVersion', function (Builder $version) use ($request): void {
                if ($request->filled('price_min')) {
                    $version->where('price_amount', '>=', $request->input('price_min'));
                }
                if ($request->filled('price_max')) {
                    $version->where('price_amount', '<=', $request->input('price_max'));
                }
            });
        }

        if ($request->has('verified') && $request->input('verified') !== null) {
            $this->constrainVerified($query, (bool) $request->boolean('verified'));
        }

        if ($request->filled('q')) {
            $this->constrainKeyword($query, $request->string('q')->toString());
        }
    }

    private function constrainKeyword(Builder $query, string $raw): void
    {
        $term = trim($raw);

        if ($term === '') {
            return;
        }

        $like = $this->like($term);
        $statusMatch = in_array(strtolower($term), [
            CampaignStatus::Active->value,
            CampaignStatus::Expiring->value,
        ], true) ? strtolower($term) : null;

        $query->where(function (Builder $outer) use ($like, $statusMatch): void {
            $outer->where('campaigns.title', 'like', $like)
                ->orWhereHas('currentVersion', function (Builder $version) use ($like): void {
                    $version->where(function (Builder $fields) use ($like): void {
                        $fields->where('product_name', 'like', $like)
                            ->orWhere('product_description', 'like', $like)
                            ->orWhere('service_area', 'like', $like)
                            ->orWhere('commission_type', 'like', $like)
                            ->orWhere('commission_trigger', 'like', $like);
                    });
                })
                ->orWhereHas('category', function (Builder $category) use ($like): void {
                    $category->where('name', 'like', $like);
                })
                ->orWhereHas('user.businessProfile', function (Builder $profile) use ($like): void {
                    $profile->where('legal_name', 'like', $like)
                        ->orWhere('trading_name', 'like', $like)
                        ->orWhere('operating_location', 'like', $like);
                });

            if ($statusMatch !== null) {
                $outer->orWhere('campaigns.status', $statusMatch);
            }
        });
    }

    private function constrainVerified(Builder $query, bool $verified): void
    {
        $requiredIds = VerificationRequirement::query()
            ->active()
            ->forParticipant(Role::Business)
            ->where('is_required', true)
            ->pluck('id');

        if ($requiredIds->isEmpty()) {
            if ($verified) {
                $query->whereRaw('0 = 1');
            }

            return;
        }

        $count = $requiredIds->count();
        $placeholders = implode(',', array_fill(0, $count, '?'));
        $approved = VerificationSubmissionStatus::Approved->value;
        $bindings = [...$requiredIds->all(), $approved];
        $operator = $verified ? '=' : '<';

        $query->whereHas('user', function (Builder $user) use ($placeholders, $bindings, $operator, $count): void {
            $user->whereRaw(
                "(select count(*) from verification_submissions vs where vs.user_id = users.id and vs.verification_requirement_id in ({$placeholders}) and vs.status = ?) {$operator} ?",
                [...$bindings, $count],
            );
        });
    }

    private function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
