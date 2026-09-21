<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\OverallVerificationStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Enums\Role;
use App\Enums\VerificationSubmissionStatus;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Models\VerificationSubmission;
use App\Services\Verification\VerificationStatusCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Admin Overview aggregates from existing transactional domains.
 *
 * Platform payment volume (Business → MarcatursHub) is never conflated with
 * Business → Ambassador commission obligations.
 */
class AdminOverviewService
{
    public function __construct(
        private readonly VerificationStatusCalculator $verification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $now = null): array
    {
        $now = $now?->copy() ?? now();
        $timezone = (string) config('app.timezone');

        return [
            'generated_at' => $now->toIso8601String(),
            'timezone' => $timezone,
            'users' => $this->users(),
            'campaigns' => $this->campaigns(),
            'deals' => $this->deals(),
            'commissions' => $this->commissions($now),
            'disputes' => $this->disputes(),
            'verification' => $this->verification(),
            'platform_payments' => $this->platformPayments($now, $timezone),
            'attention' => $this->attention($now),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function users(): array
    {
        $participants = User::query()
            ->whereIn('role', [Role::Business->value, Role::Ambassador->value])
            ->select('role', 'status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('role', 'status')
            ->get();

        $businessRegistered = 0;
        $ambassadorRegistered = 0;
        $businessActive = 0;
        $ambassadorActive = 0;
        $restricted = 0;
        $suspended = 0;
        $banned = 0;

        foreach ($participants as $row) {
            $count = (int) $row->aggregate_count;
            $role = $row->role instanceof Role ? $row->role : Role::from((string) $row->role);
            $status = $row->status instanceof AccountStatus
                ? $row->status
                : AccountStatus::from((string) $row->status);

            if ($role === Role::Business) {
                $businessRegistered += $count;
                if ($status === AccountStatus::Active) {
                    $businessActive += $count;
                }
            }

            if ($role === Role::Ambassador) {
                $ambassadorRegistered += $count;
                if ($status === AccountStatus::Active) {
                    $ambassadorActive += $count;
                }
            }

            match ($status) {
                AccountStatus::Restricted => $restricted += $count,
                AccountStatus::Suspended => $suspended += $count,
                AccountStatus::Banned => $banned += $count,
                default => null,
            };
        }

        return [
            'business_registered' => $businessRegistered,
            'ambassador_registered' => $ambassadorRegistered,
            'business_active' => $businessActive,
            'ambassador_active' => $ambassadorActive,
            'restricted' => $restricted,
            'suspended' => $suspended,
            'banned' => $banned,
            'business_verified' => $this->countVerified(Role::Business),
            'ambassador_verified' => $this->countVerified(Role::Ambassador),
        ];
    }

    private function countVerified(Role $role): int
    {
        $users = User::query()
            ->where('role', $role)
            ->get(['id', 'role']);

        if ($users->isEmpty()) {
            return 0;
        }

        $statuses = $this->verification->overallMany($users);

        return collect($statuses)
            ->filter(fn (OverallVerificationStatus $status) => $status === OverallVerificationStatus::Verified)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function campaigns(): array
    {
        $byStatus = [];
        foreach (CampaignStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        $rows = Campaign::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status instanceof CampaignStatus
                ? $row->status->value
                : (string) $row->status;
            $byStatus[$status] = (int) $row->aggregate_count;
        }

        return [
            'by_status' => $byStatus,
            'featured_flagged' => (int) Campaign::query()->where('is_featured', true)->count(),
            'awaiting_admin_review' => $byStatus[CampaignStatus::Submitted->value] ?? 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function deals(): array
    {
        $byStatus = [
            DealStatus::PaymentPending->value => 0,
            DealStatus::Sealed->value => 0,
            DealStatus::Completed->value => 0,
            DealStatus::Cancelled->value => 0,
        ];

        $rows = Deal::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status instanceof DealStatus
                ? $row->status->value
                : (string) $row->status;
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = (int) $row->aggregate_count;
            }
        }

        $paymentPending = $byStatus[DealStatus::PaymentPending->value];
        $sealed = $byStatus[DealStatus::Sealed->value];
        $completed = $byStatus[DealStatus::Completed->value];
        $cancelled = $byStatus[DealStatus::Cancelled->value];

        return [
            'total' => $paymentPending + $sealed + $completed + $cancelled,
            'payment_pending' => $paymentPending,
            'sealed' => $sealed,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'payment_confirmed' => $sealed + $completed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissions(Carbon $now): array
    {
        $byStatus = [
            CommissionStatus::Due->value => 0,
            CommissionStatus::Paid->value => 0,
            CommissionStatus::Received->value => 0,
        ];

        $rows = Commission::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status instanceof CommissionStatus
                ? $row->status->value
                : (string) $row->status;
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = (int) $row->aggregate_count;
            }
        }

        $overdue = (int) Commission::query()
            ->where('status', CommissionStatus::Due)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->count();

        return [
            'due' => $byStatus[CommissionStatus::Due->value],
            'overdue' => $overdue,
            'paid' => $byStatus[CommissionStatus::Paid->value],
            'received' => $byStatus[CommissionStatus::Received->value],
            'boundary' => 'Business_to_Ambassador_obligation_not_platform_revenue',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disputes(): array
    {
        $byStatus = [];
        foreach (DisputeStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        $rows = Dispute::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status instanceof DisputeStatus
                ? $row->status->value
                : (string) $row->status;
            $byStatus[$status] = (int) $row->aggregate_count;
        }

        $open = 0;
        foreach (DisputeStatus::openValues() as $openStatus) {
            $open += $byStatus[$openStatus] ?? 0;
        }

        return [
            'open' => $open,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function verification(): array
    {
        $byStatus = [];
        foreach (VerificationSubmissionStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        $rows = VerificationSubmission::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status instanceof VerificationSubmissionStatus
                ? $row->status->value
                : (string) $row->status;
            $byStatus[$status] = (int) $row->aggregate_count;
        }

        return [
            'submissions_awaiting_review' => ($byStatus[VerificationSubmissionStatus::Pending->value] ?? 0)
                + ($byStatus[VerificationSubmissionStatus::UnderReview->value] ?? 0),
            'submissions_more_information_required' => $byStatus[VerificationSubmissionStatus::MoreInformationRequired->value] ?? 0,
            'submissions_approved' => $byStatus[VerificationSubmissionStatus::Approved->value] ?? 0,
            'submissions_rejected' => $byStatus[VerificationSubmissionStatus::Rejected->value] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformPayments(Carbon $now, string $timezone): array
    {
        $todayStart = $now->copy()->timezone($timezone)->startOfDay()->utc();
        $todayEnd = $now->copy()->timezone($timezone)->endOfDay()->utc();
        $monthStart = $now->copy()->timezone($timezone)->startOfMonth()->utc();
        $monthEnd = $now->copy()->timezone($timezone)->endOfMonth()->utc();

        return [
            'terminology' => 'successful_platform_payment_volume',
            'boundary' => 'Business_or_Ambassador_to_MarcatursHub_for_platform_products',
            'success_definition' => 'platform_payments.status=paid',
            'all_time' => $this->platformPaymentWindow(null, null),
            'today' => $this->platformPaymentWindow($todayStart, $todayEnd),
            'this_month' => $this->platformPaymentWindow($monthStart, $monthEnd),
        ];
    }

    /**
     * @return array{by_currency: list<array<string, mixed>>}
     */
    private function platformPaymentWindow(?Carbon $paidFromInclusive, ?Carbon $paidToInclusive): array
    {
        $query = PlatformPayment::query()
            ->where('status', PlatformPaymentStatus::Paid)
            ->select(
                'currency',
                'purpose',
                DB::raw('COUNT(*) as successful_payment_count'),
                DB::raw('SUM(amount_minor) as successful_amount_minor'),
            )
            ->groupBy('currency', 'purpose');

        if ($paidFromInclusive !== null) {
            $query->where('paid_at', '>=', $paidFromInclusive);
        }
        if ($paidToInclusive !== null) {
            $query->where('paid_at', '<=', $paidToInclusive);
        }

        $rows = $query->get();

        /** @var array<string, array<string, mixed>> $byCurrency */
        $byCurrency = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $purpose = $row->purpose instanceof PlatformPaymentPurpose
                ? $row->purpose->value
                : (string) $row->purpose;
            $count = (int) $row->successful_payment_count;
            $amount = (int) $row->successful_amount_minor;

            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'currency' => $currency,
                    'successful_payment_count' => 0,
                    'successful_amount_minor' => 0,
                    'by_purpose' => [
                        PlatformPaymentPurpose::CampaignExtension->value => [
                            'successful_payment_count' => 0,
                            'successful_amount_minor' => 0,
                        ],
                        PlatformPaymentPurpose::CampaignFeatured->value => [
                            'successful_payment_count' => 0,
                            'successful_amount_minor' => 0,
                        ],
                        PlatformPaymentPurpose::CertificationEnrollment->value => [
                            'successful_payment_count' => 0,
                            'successful_amount_minor' => 0,
                        ],
                    ],
                ];
            }

            $byCurrency[$currency]['successful_payment_count'] += $count;
            $byCurrency[$currency]['successful_amount_minor'] += $amount;

            if (isset($byCurrency[$currency]['by_purpose'][$purpose])) {
                $byCurrency[$currency]['by_purpose'][$purpose]['successful_payment_count'] += $count;
                $byCurrency[$currency]['by_purpose'][$purpose]['successful_amount_minor'] += $amount;
            }
        }

        ksort($byCurrency);

        return [
            'by_currency' => array_values($byCurrency),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function attention(Carbon $now): array
    {
        return [
            'campaigns_awaiting_review' => (int) Campaign::query()
                ->where('status', CampaignStatus::Submitted)
                ->count(),
            'verification_submissions_awaiting_review' => (int) VerificationSubmission::query()
                ->whereIn('status', [
                    VerificationSubmissionStatus::Pending,
                    VerificationSubmissionStatus::UnderReview,
                ])
                ->count(),
            'open_disputes' => (int) Dispute::query()->open()->count(),
            'commissions_overdue' => (int) Commission::query()
                ->where('status', CommissionStatus::Due)
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now)
                ->count(),
            'deals_payment_pending' => (int) Deal::query()
                ->where('status', DealStatus::PaymentPending)
                ->count(),
            'reported_conversations' => (int) Conversation::query()
                ->whereNotNull('reported_at')
                ->count(),
        ];
    }
}
