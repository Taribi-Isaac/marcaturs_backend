<?php

namespace App\Services\Deals;

use App\Enums\CommissionStatus;
use App\Models\Commission;
use App\Models\Deal;
use App\Support\Money\CommissionDeadline;

class CommissionLiabilityService
{
    public function createForSealedDeal(Deal $deal): Commission
    {
        if ($deal->commission_amount === null) {
            throw new \RuntimeException('A sealed Deal is missing an authoritative commission amount.');
        }

        $commission = new Commission;
        $commission->deal_id = $deal->id;
        $commission->business_user_id = $deal->business_user_id;
        $commission->ambassador_user_id = $deal->ambassador_user_id;
        $commission->campaign_version_id = $deal->campaign_version_id;
        $commission->status = CommissionStatus::Due;
        $commission->commission_type = $deal->commission_type;
        $commission->commission_rate = $deal->commission_rate;
        $commission->amount = $deal->commission_amount;
        $commission->currency = $deal->price_currency ?: 'NGN';
        $commission->became_due_at = $deal->confirmed_at ?? now();
        $commission->due_at = CommissionDeadline::dueAt($deal);
        $commission->save();

        return $commission;
    }
}
