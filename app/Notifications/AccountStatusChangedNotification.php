<?php

namespace App\Notifications;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Enums\UserStatusAction;
use App\Models\UserStatusEvent;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email-only participant notice when an Admin changes account status (MH-BE-039).
 */
class AccountStatusChangedNotification extends BaseNotification
{
    public function __construct(
        private readonly int $eventId,
        private readonly int $recipientUserId,
    ) {
        parent::__construct();
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::AccountStatusChanged;
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->toMail($notifiable) !== null ? ['mail'] : [];
    }

    public function idempotencyKey(): ?string
    {
        return sprintf(
            'account-status:%d:%d',
            $this->eventId,
            $this->recipientUserId,
        );
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        unset($channel);

        $event = UserStatusEvent::query()->find($this->eventId);

        return $event !== null && $event->target_user_id === $this->recipientUserId;
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        $event = $this->loadEvent();

        return (new MailMessage)
            ->subject($this->mailSubject($event->new_status))
            ->line($this->mailIntro($event))
            ->line('Reason provided by MarcatursHub administration: '.$event->reason)
            ->line($this->mailGuidance($event->new_status));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $event = $this->loadEvent();

        return [
            'event_id' => $event->id,
            'previous_status' => $event->previous_status->value,
            'new_status' => $event->new_status->value,
            'action' => $event->action->value,
        ];
    }

    private function loadEvent(): UserStatusEvent
    {
        return UserStatusEvent::query()->findOrFail($this->eventId);
    }

    private function mailSubject(AccountStatus $status): string
    {
        return match ($status) {
            AccountStatus::Restricted => 'Your MarcatursHub account has been restricted',
            AccountStatus::Suspended => 'Your MarcatursHub account has been suspended',
            AccountStatus::Banned => 'Your MarcatursHub account has been banned',
            AccountStatus::Active => 'Your MarcatursHub account access has been restored',
        };
    }

    private function mailIntro(UserStatusEvent $event): string
    {
        return match ($event->action) {
            UserStatusAction::Restrict => 'Your MarcatursHub account status is now restricted. Marketplace and operational features remain limited.',
            UserStatusAction::Suspend => 'Your MarcatursHub account has been suspended. You can no longer sign in to use platform features.',
            UserStatusAction::Ban => 'Your MarcatursHub account has been banned. Access to the platform is blocked.',
            UserStatusAction::Restore => $event->new_status === AccountStatus::Restricted
                ? 'Your MarcatursHub ban has been lifted. Your account is now restricted while access is reviewed.'
                : 'Your MarcatursHub account has been restored to active status.',
        };
    }

    private function mailGuidance(AccountStatus $status): string
    {
        return match ($status) {
            AccountStatus::Active => 'You may sign in and continue using MarcatursHub subject to the platform terms.',
            AccountStatus::Restricted => 'You may still sign in for limited account actions. Contact support if you believe this decision was made in error.',
            AccountStatus::Suspended, AccountStatus::Banned => 'If you believe this decision was made in error, contact MarcatursHub support with your account email.',
        };
    }
}
