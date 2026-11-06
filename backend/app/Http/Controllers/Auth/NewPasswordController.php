<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => UserPassword::rules(),
        ]);

        $status = DB::transaction(function () use ($validated) {
            return Password::reset(
                $validated,
                function (User $user, string $password) {
                    UserPassword::update($user, $password);

                    event(new PasswordReset($user));
                }
            );
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => 'Link di recupero non valido o scaduto. Richiedine uno nuovo.',
            ]);
        }

        return response()->json([
            'message' => 'Password aggiornata. Accedi con la nuova password.',
        ]);
    }
}
