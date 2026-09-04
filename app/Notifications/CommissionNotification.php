<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Commission status and payment-pressure reminder notification.
 *
 * One logical notification fans out to database (in-app) + mail.
 * Idempotency is enforced via {@see idempotencyKey()}.
 * Delivery re-checks Commission state via {@see shouldSend()}.
 */
class CommissionNotification extends BaseNotification
{
    public function __construct(
        private readonly int $commissionId,
        private readonly NotificationType $type,
        private readonly string $scheduledDate,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return $this->type;
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'commission:%d:reminder:%s:%s:%d',
            $this->commissionId,
            $this->type->value,
            $this->scheduledDate,
            $this->recipientUserId,
        );
    }

    /**
     * Re-check eligibility immediately before channel delivery.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        $commission = Commission::query()->find($this->commissionId);

        if ($commission === null) {
            return false;
        }

        return match ($this->type) {
            NotificationType::CommissionPreDeadline,
            NotificationType::CommissionDeadline,
            NotificationType::CommissionOverdue,
            NotificationType::CommissionOverdueFollowUp => $commission->status->isDue(),
            NotificationType::CommissionDue => $commission->status->isDue()
                || $commission->status->isPaid()
                || $commission->status->isReceived(),
            NotificationType::CommissionPaid => $commission->status->isPaid()
                || $commission->status->isReceived(),
            NotificationType::CommissionReceived => $commission->status->isReceived(),
            default => false,
        };
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $commission = $this->loadCommission();
        $subject = $this->mailSubject($commission);

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($this->mailIntro($commission, $notifiable));

        $mail->line('Amount: '.$commission->amount.' '.$commission->currency);
        $mail->line('Due date: '.$commission->due_at?->toIso8601String());

        if ($this->type->isBusinessPaymentPressureReminder() || $this->type === NotificationType::CommissionDue) {
            $days = $this->daysDelta($commission);
            if ($days !== null) {
                $mail->line($days >= 0
                    ? "Days remaining: {$days}"
                    : 'Days overdue: '.abs($days));
            }
        }

        $action = $this->actionUrl($commission);
        if ($action !== null) {
            $mail->action('View Commission', $action);
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $commission = $this->loadCommission();
        $isBusinessRecipient = $commission->business_user_id === $this->recipientUserId;

        $payload = [
            'commission_id' => $commission->id,
            'deal_id' => $commission->deal_id,
            'amount' => $commission->amount,
            'currency' => $commission->currency,
            'status' => $commission->status->value,
            'due_at' => $commission->due_at?->toIso8601String(),
            'scheduled_date' => $this->scheduledDate,
            'action_url' => $this->actionUrl($commission),
        ];

        $days = $this->daysDelta($commission);
        if ($days !== null) {
            if ($days >= 0) {
                $payload['days_remaining'] = $days;
            } else {
                $payload['days_overdue'] = abs($days);
            }
        }

        if ($isBusinessRecipient) {
            $payload['ambassador'] = $this->userSummary($commission->ambassador);
        } else {
            $payload['business'] = $this->userSummary($commission->business);
        }

        $campaignTitle = $commission->deal?->campaign?->title ?? null;
        if ($campaignTitle !== null) {
            $payload['campaign'] = ['title' => $campaignTitle];
        }

        return $payload;
    }

    private function loadCommission(): Commission
    {
        return Commission::query()
            ->with(['business', 'ambassador', 'deal.campaign'])
            ->findOrFail($this->commissionId);
    }

    private function mailSubject(Commission $commission): string
    {
        return match ($this->type) {
            NotificationType::CommissionDue => 'MarcatursHub: Commission is due',
            NotificationType::CommissionPreDeadline => 'MarcatursHub: Commission payment deadline approaching',
            NotificationType::CommissionDeadline => 'MarcatursHub: Commission payment deadline today',
            NotificationType::CommissionOverdue => 'MarcatursHub: Commission payment is overdue',
            NotificationType::CommissionOverdueFollowUp => 'MarcatursHub: Commission payment still overdue',
            NotificationType::CommissionPaid => 'MarcatursHub: Commission marked paid',
            NotificationType::CommissionReceived => 'MarcatursHub: Commission receipt confirmed',
            default => 'MarcatursHub: Commission update',
        };
    }

    private function mailIntro(Commission $commission, mixed $notifiable): string
    {
        unset($notifiable);

        return match ($this->type) {
            NotificationType::CommissionDue => 'A commission liability is now due for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionPreDeadline => 'A commission payment deadline is approaching for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionDeadline => 'A commission payment is due today for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionOverdue => 'A commission payment is overdue for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionOverdueFollowUp => 'A commission payment remains overdue for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionPaid => 'The Business has marked commission as paid for Deal #'.$commission->deal_id.'.',
            NotificationType::CommissionReceived => 'The Ambassador has confirmed commission receipt for Deal #'.$commission->deal_id.'.',
            default => 'Commission update for Deal #'.$commission->deal_id.'.',
        };
    }

    private function daysDelta(Commission $commission): ?int
    {
        if ($commission->due_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($commission->due_at->copy()->startOfDay(), false);
    }

    private function actionUrl(Commission $commission): ?string
    {
        $base = rtrim((string) env('FRONTEND_URL', ''), '/');

        if ($base === '') {
            return null;
        }

        return $base.'/commissions/'.$commission->id;
    }

    /**
     * @return array{id: int, role: string}|null
     */
    private function userSummary(?User $user): ?array
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
