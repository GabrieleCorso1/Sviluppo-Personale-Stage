<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Amministratore',
                'description' => 'Gestisce il sistema e gli utenti.',
            ],
            [
                'name' => 'user',
                'display_name' => 'Utente',
                'description' => 'Utente standard del sistema.',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
