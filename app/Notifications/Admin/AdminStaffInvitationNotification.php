<?php

namespace App\Notifications\Admin;

use App\Models\AdminStaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Staff invitation email (mail channel only).
 * Invitation tokens must never be persisted to the in-app notifications table.
 */
class AdminStaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AdminStaffInvitation $invitation,
        #[\SensitiveParameter]
        private readonly string $plainToken,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $base = rtrim((string) config('admin_staff.admin_frontend_url', env('ADMIN_FRONTEND_URL', '')), '/');
        $url = $base.'/staff/accept-invitation?'.http_build_query([
            'token' => $this->plainToken,
        ]);

        $hours = (int) config('admin_staff.invitation_ttl_hours', 72);

        return (new MailMessage)
            ->subject('MarcatursHub Admin staff invitation')
            ->greeting('Hello '.$this->invitation->name.',')
            ->line('You have been invited to join MarcatursHub Admin Control as '.$this->invitation->staff_role->value.'.')
            ->action('Accept invitation', $url)
            ->line("This invitation expires in {$hours} hours.")
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
