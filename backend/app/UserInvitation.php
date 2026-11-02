<?php

namespace App;

use App\Models\User;
use App\Notifications\SetInitialPassword;
use Illuminate\Support\Facades\Password;
use Throwable;

class UserInvitation
{
    public function send(User $user): array
    {
        $url = null;
        try {
            $status = Password::sendResetLink(['email' => $user->email], function (User $recipient, string $token) use (&$url) {
                $notification = new SetInitialPassword($token);
                $recipient->notify($notification);
                if (app()->environment('local') && config('app.debug') && config('auth.expose_reset_url')) {
                    $url = $notification->invitationUrl($recipient);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'failed'];
        }

        if ($status !== Password::ResetLinkSent) {
            return ['status' => 'failed'];
        }

        return $url === null ? ['status' => 'sent'] : ['status' => 'sent', 'reset_url' => $url];
    }
}
