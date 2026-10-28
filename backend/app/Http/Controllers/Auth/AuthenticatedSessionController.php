<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $key = 'login:'.hash(
            'sha256',
            strtolower(trim($request->input('email'))).'|'.$request->ip()
        );

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Troppi tentativi. Riprova tra {$seconds} secondi.",
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check(
            $request->input('password'),
            $user->password
        )) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Email o password non corrette.',
            ]);
        }

        RateLimiter::clear($key);

        $expiresAt = $request->boolean('remember')
            ? now()->addDays(30)
            : now()->addHours(8);

        $token = $user->createToken(
            'web-login',
            ['*'],
            $expiresAt
        );

        return response()->json([
            'message' => 'Accesso effettuato.',
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Disconnessione effettuata.',
        ]);
    }
}
