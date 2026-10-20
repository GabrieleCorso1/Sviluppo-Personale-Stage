<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Ruoli
        $roles = [
            [
                'alias' => 'Admin',
                'description' => 'Amministratore della piattaforma.',
            ],
            [
                'alias' => 'Project Manager',
                'description' => 'Gestisce progetti, pianificazione e risorse.',
            ],
            [
                'alias' => 'Team Leader',
                'description' => 'Coordina il team e le attività operative.',
            ],
            [
                'alias' => 'User',
                'description' => 'Utente standard della piattaforma.',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['alias' => $role['alias']],
                [
                    'description' => $role['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Recupera gli ID dei ruoli
        $roleIds = DB::table('roles')
            ->whereIn('alias', array_column($roles, 'alias'))
            ->pluck('id', 'alias');

        // 2. Permessi
        $permissions = [
            [
                'name' => 'users.view',
                'description' => 'Visualizzare gli utenti.',
            ],
            [
                'name' => 'users.manage',
                'description' => 'Creare e modificare gli utenti.',
            ],
            [
                'name' => 'projects.view',
                'description' => 'Visualizzare i progetti.',
            ],
            [
                'name' => 'projects.manage',
                'description' => 'Creare e modificare i progetti.',
            ],
            [
                'name' => 'sprints.manage',
                'description' => 'Gestire gli sprint.',
            ],
            [
                'name' => 'capacities.manage',
                'description' => 'Gestire le capacità e le allocazioni.',
            ],
            [
                'name' => 'calendar.manage',
                'description' => 'Gestire le richieste del calendario.',
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Recupera gli ID dei permessi
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column($permissions, 'name'))
            ->pluck('id', 'name');

        // 3. Associa i permessi ai ruoli
        $permissionsByRole = [
            'Admin' => [
                'users.view',
                'users.manage',
                'projects.view',
                'projects.manage',
                'sprints.manage',
                'capacities.manage',
                'calendar.manage',
            ],
            'Project Manager' => [
                'users.view',
                'projects.view',
                'projects.manage',
                'sprints.manage',
                'capacities.manage',
                'calendar.manage',
            ],
            'Team Leader' => [
                'users.view',
                'projects.view',
                'sprints.manage',
                'capacities.manage',
                'calendar.manage',
            ],
            'User' => [
                'projects.view',
                'calendar.manage',
            ],
        ];

        foreach ($permissionsByRole as $roleAlias => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $roleIds[$roleAlias],
                        'permission_id' => $permissionIds[$permissionName],
                    ],
                    []
                );
            }
        }
    }
}