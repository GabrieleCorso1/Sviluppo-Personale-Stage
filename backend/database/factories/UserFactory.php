<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => null,
            'email' => fake()->unique()->safeEmail(),
            'azure_email' => fake()->safeEmail(),
            'company_email' => fake()->safeEmail(),
            'is_intern' => false,
            'isAllocation_visible' => true,
            'birth_date' => '1990-01-01',
            'hiring_date' => '2020-01-01',
            'resignation_date' => null,
            'remember_me' => false,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'filestorage_id' => fn () => DB::table('filestorage')->insertGetId([
                'path' => 'tests/avatars',
                'name' => 'test-avatar',
                'extension' => 'png',
                'type' => 'image',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];
    }
}
