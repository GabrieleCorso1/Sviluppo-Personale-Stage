<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserPassword
{
    public static function rules(): array
    {
        return ['required', 'string', 'min:8', 'confirmed'];
    }

    public static function update(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();
        $user->tokens()->delete();
    }
}
