<?php

namespace App\Notifications;

use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Models\Dispute;
use Illuminate\Notifications\Messages\MailMessage;

class DisputeNotification extends BaseNotification
{
    public function __construct(
        private readonly int $disputeId,
        private readonly NotificationType $type,
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
            'dispute:%d:%s:%d',
            $this->disputeId,
            $this->type->value,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        $dispute = Dispute::query()->find($this->disputeId);

        if ($dispute === null) {
            return false;
        }

        return match ($this->type) {
            NotificationType::DisputeOpened => $dispute->status !== DisputeStatus::Closed,
            NotificationType::DisputeResolved => in_array(
                $dispute->status,
                [DisputeStatus::Resolved, DisputeStatus::Closed],
                true,
            ),
            default => false,
        };
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $dispute = $this->loadDispute();

        return (new MailMessage)
            ->subject($this->mailSubject())
            ->line($this->mailIntro($dispute));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $dispute = $this->loadDispute();

        return [
            'dispute_id' => $dispute->id,
            'reference' => $dispute->reference,
            'deal_id' => $dispute->deal_id,
            'status' => $dispute->status->value,
        ];
    }

    private function loadDispute(): Dispute
    {
        return Dispute::query()->findOrFail($this->disputeId);
    }

    private function mailSubject(): string
    {
        return match ($this->type) {
            NotificationType::DisputeOpened => 'MarcatursHub: Dispute opened',
            NotificationType::DisputeResolved => 'MarcatursHub: Dispute resolved',
            default => 'MarcatursHub: Dispute update',
        };
    }

    private function mailIntro(Dispute $dispute): string
    {
        return match ($this->type) {
            NotificationType::DisputeOpened => 'A dispute ('.$dispute->reference.') has been opened on Deal #'.$dispute->deal_id.'.',
            NotificationType::DisputeResolved => 'Dispute '.$dispute->reference.' on Deal #'.$dispute->deal_id.' has been resolved.',
            default => 'There is an update on dispute '.$dispute->reference.'.',
        };
    }
}
