<?php

namespace App\Services\Admin;

use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Models\Deal;
use App\Models\PaymentEvidence;
use App\Services\Deals\PaymentEvidenceStore;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminDealService
{
    public function __construct(
        private readonly PaymentEvidenceStore $files,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Deal>
     */
    public function list(
        ?DealStatus $status,
        ?string $search,
        ?bool $openDispute,
        ?bool $commissionOverdue,
        ?CommissionStatus $commissionStatus,
        int $perPage,
    ): LengthAwarePaginator {
        $query = Deal::query()
            ->with([
                'business',
                'ambassador',
                'campaign',
                'campaignVersion',
                'commission',
            ])
            ->withCount([
                'paymentEvidences as evidence_count',
                'disputes as open_dispute_count' => function (Builder $disputes): void {
                    $disputes->whereIn('status', DisputeStatus::openValues());
                },
            ])
            ->orderByDesc('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, trim($search));
        }

        if ($openDispute === true) {
            $query->whereHas('disputes', function (Builder $disputes): void {
                $disputes->whereIn('status', DisputeStatus::openValues());
            });
        }

        if ($commissionOverdue === true) {
            $query->whereHas('commission', function (Builder $commission): void {
                $commission->where('status', CommissionStatus::Due->value)
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now());
            });
        }

        if ($commissionStatus !== null) {
            $query->whereHas('commission', function (Builder $commission) use ($commissionStatus): void {
                $commission->where('status', $commissionStatus);
            });
        }

        $paginator = $query->paginate($perPage);
        $this->attachListEvidenceIndicators($paginator->getCollection());

        return $paginator;
    }

    public function show(int $dealId): Deal
    {
        $deal = Deal::query()
            ->with([
                'business',
                'ambassador',
                'campaign.category',
                'campaignVersion',
                'commission',
                'paymentEvidences.ambassador',
                'events.actor',
                'disputes.category',
            ])
            ->withCount([
                'paymentEvidences as evidence_count',
                'disputes as open_dispute_count' => function (Builder $disputes): void {
                    $disputes->whereIn('status', DisputeStatus::openValues());
                },
            ])
            ->whereKey($dealId)
            ->first();

        if ($deal === null) {
            throw (new ModelNotFoundException)->setModel(Deal::class, [$dealId]);
        }

        $this->attachListEvidenceIndicators(collect([$deal]));

        return $deal;
    }

    public function streamEvidence(int $dealId, int $evidenceId, int $actorUserId): StreamedResponse
    {
        $deal = Deal::query()->whereKey($dealId)->first();
        if ($deal === null) {
            throw (new ModelNotFoundException)->setModel(Deal::class, [$dealId]);
        }

        $evidence = PaymentEvidence::query()
            ->whereKey($evidenceId)
            ->where('deal_id', $deal->id)
            ->first();

        if ($evidence === null || ! $evidence->hasFile()) {
            throw (new ModelNotFoundException)->setModel(PaymentEvidence::class, [$evidenceId]);
        }

        Log::info('admin.deal.payment_evidence.download', [
            'actor_user_id' => $actorUserId,
            'deal_id' => $deal->id,
            'payment_evidence_id' => $evidence->id,
            'mime_type' => $evidence->mime_type,
            'size_bytes' => $evidence->size_bytes,
        ]);

        return $this->files->stream($evidence);
    }

    /**
     * @param  Builder<Deal>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $exactId = ctype_digit($term) ? (int) $term : null;

        $query->where(function (Builder $outer) use ($like, $exactId): void {
            if ($exactId !== null) {
                $outer->where('deals.id', $exactId);
            }

            $outer->orWhere('deals.product_name', 'like', $like)
                ->orWhereHas('business', function (Builder $business) use ($like): void {
                    $business->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orWhereHas('ambassador', function (Builder $ambassador) use ($like): void {
                    $ambassador->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orWhereHas('campaign', function (Builder $campaign) use ($like): void {
                    $campaign->where('title', 'like', $like);
                })
                ->orWhereHas('paymentEvidences', function (Builder $evidence) use ($like): void {
                    $evidence->where('reference_number', 'like', $like);
                });
        });
    }

    /**
     * @param  Collection<int, Deal>  $deals
     */
    private function attachListEvidenceIndicators($deals): void
    {
        if ($deals->isEmpty()) {
            return;
        }

        $ids = $deals->pluck('id')->all();
        $latestByDeal = PaymentEvidence::query()
            ->whereIn('deal_id', $ids)
            ->orderByDesc('id')
            ->get()
            ->groupBy('deal_id')
            ->map(fn ($group) => $group->first());

        foreach ($deals as $deal) {
            $count = (int) ($deal->evidence_count ?? 0);
            $latest = $latestByDeal->get($deal->id);
            $deal->setAttribute('has_evidence', $count > 0);
            $deal->setAttribute('latest_evidence_status', $latest?->status?->value);
        }
    }
}
