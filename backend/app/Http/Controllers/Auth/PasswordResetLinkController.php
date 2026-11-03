<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $message = 'Se l’indirizzo è associato a un account, riceverai le istruzioni per reimpostare la password.';

        if (app()->environment('local') && config('app.debug') && config('auth.expose_reset_url')) {
            $resetUrl = null;

            Password::sendResetLink(
                $validated,
                function (User $user, string $token) use (&$resetUrl) {
                    $resetUrl = rtrim(config('app.frontend_url'), '/')
                        .'/reset-password?'
                        .http_build_query([
                            'token' => $token,
                            'email' => $user->getEmailForPasswordReset(),
                        ]);
                }
            );

            return response()->json([
                'message' => $message,
                'reset_url' => $resetUrl,
            ]);
        }

        Password::sendResetLink($validated);

        return response()->json([
            'message' => $message,
        ]);
    }
}
