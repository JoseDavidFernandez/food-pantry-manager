<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email with the invitation link (RN-39). Sent through the configured mailer: in development it
 * is the `log` mailer, which writes the email to the log and sends nothing (DT-05).
 */
class HouseholdInvitationNotification extends Notification
{
    public function __construct(
        private readonly HouseholdInvitation $invitation,
        private readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $household = $this->invitation->household->name;
        $inviter = $this->invitation->inviter->name ?? 'Un miembro';

        return (new MailMessage)
            ->subject("Invitación para unirte a «{$household}»")
            ->greeting('¡Hola!')
            ->line("{$inviter} te invita a unirte al hogar «{$household}» en Food & Pantry Manager como {$this->invitation->role->label()}.")
            ->action('Ver la invitación', $this->url)
            ->line('La invitación caduca el '.$this->invitation->expires_at->timezone('Europe/Madrid')->format('d/m/Y H:i').'. Si no tienes cuenta, regístrate con este mismo correo.')
            ->line('Si no esperabas esta invitación, puedes ignorar este correo.');
    }
}
