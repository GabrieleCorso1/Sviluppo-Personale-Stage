<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $userRole = Role::where('name', 'user')->firstOrFail();

        $users = [
            [
                'first_name' => 'Admin',
                'last_name' => 'Sistema',
                'email' => 'admin@example.com',
                'password' => Hash::make('Admin123!'),
                'birth_date' => '1990-10-15',
                'hiring_date' => '2020-01-01',
            ],
            [
                'first_name' => 'Mario',
                'last_name' => 'Rossi',
                'email' => 'mario.rossi@example.com',
                'password' => Hash::make('User123!'),
                'birth_date' => '1995-05-10',
                'hiring_date' => '2022-03-01',
            ],
            [
                'first_name' => 'Giulia',
                'last_name' => 'Bianchi',
                'email' => 'giulia.bianchi@example.com',
                'password' => Hash::make('User123!'),
                'birth_date' => '1998-09-20',
                'hiring_date' => '2023-06-15',
            ],
            [
                'first_name' => 'Luca',
                'last_name' => 'Verdi',
                'email' => 'luca.verdi@example.com',
                'password' => Hash::make('User123!'),
                'birth_date' => '1992-12-03',
                'hiring_date' => '2021-11-01',
            ],
        ];

        foreach ($users as $index => $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );

            $user->roles()->syncWithoutDetaching([
                $index === 0 ? $adminRole->id : $userRole->id,
            ]);
        }
    }
}
