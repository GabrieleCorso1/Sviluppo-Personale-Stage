<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class SetInitialPassword extends ResetPassword
{
    public function invitationUrl(User $user): string
    {
        return $this->resetUrl($user);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Imposta la password del tuo nuovo account')
            ->greeting('Benvenuto!')
            ->line('È stato creato un account per te. Scegli la tua password per effettuare il primo accesso.')
            ->action('Imposta password', $url)
            ->line('Il link scade tra '.$minutes.' minuti ed è utilizzabile una sola volta.')
            ->line('Se il link è scaduto, richiedine uno nuovo dalla pagina Password dimenticata.');
    }
}
