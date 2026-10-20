<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Recupera i file di riferimento creati da FileStorageSeeder
        $fileStorageId = DB::table('filestorage')
            ->where('name', 'default-avatar')
            ->value('id');

        if (!$fileStorageId) {
            throw new \RuntimeException(
                'File default-avatar non trovato. Esegui prima FileStorageSeeder.'
            );
        }

        // 2. Crea gli utenti
        $users = [
            [
                'first_name' => 'Mario',
                'last_name' => 'Rossi',
                'gender' => 'male',
                'email' => 'mario.rossi@example.com',
                'azure_email' => 'mario.rossi@azure.example.com',
                'company_email' => 'mario.rossi@company.example.com',
                'is_intern' => false,
                'isAllocation_visible' => true,
                'birth_date' => '1990-05-12',
                'hiring_date' => '2020-03-01',
                'resignation_date' => null,
                'role' => 'Admin',
                'assignment_roles' => [],
            ],
            [
                'first_name' => 'Laura',
                'last_name' => 'Bianchi',
                'gender' => 'female',
                'email' => 'laura.bianchi@example.com',
                'azure_email' => 'laura.bianchi@azure.example.com',
                'company_email' => 'laura.bianchi@company.example.com',
                'is_intern' => false,
                'isAllocation_visible' => true,
                'birth_date' => '1988-09-20',
                'hiring_date' => '2019-06-10',
                'resignation_date' => null,
                'role' => 'Project Manager',
                'assignment_roles' => [
                    ['alias' => 'Project Manager', 'expertise' => 'senior'],
                ],
            ],
            [
                'first_name' => 'Luca',
                'last_name' => 'Verdi',
                'gender' => 'male',
                'email' => 'luca.verdi@example.com',
                'azure_email' => 'luca.verdi@azure.example.com',
                'company_email' => 'luca.verdi@company.example.com',
                'is_intern' => false,
                'isAllocation_visible' => true,
                'birth_date' => '1992-02-14',
                'hiring_date' => '2021-01-15',
                'resignation_date' => null,
                'role' => 'Team Leader',
                'assignment_roles' => [
                    ['alias' => 'Team Leader', 'expertise' => 'senior'],
                    ['alias' => 'Sviluppatore Back-end', 'expertise' => 'senior'],
                ],
            ],
            [
                'first_name' => 'Giulia',
                'last_name' => 'Neri',
                'gender' => 'female',
                'email' => 'giulia.neri@example.com',
                'azure_email' => 'giulia.neri@azure.example.com',
                'company_email' => 'giulia.neri@company.example.com',
                'is_intern' => false,
                'isAllocation_visible' => true,
                'birth_date' => '1995-11-08',
                'hiring_date' => '2022-04-04',
                'resignation_date' => null,
                'role' => 'User',
                'assignment_roles' => [
                    ['alias' => 'Sviluppatore Front-end', 'expertise' => 'senior'],
                ],
            ],
            [
                'first_name' => 'Andrea',
                'last_name' => 'Costa',
                'gender' => 'male',
                'email' => 'andrea.costa@example.com',
                'azure_email' => 'andrea.costa@azure.example.com',
                'company_email' => 'andrea.costa@company.example.com',
                'is_intern' => true,
                'isAllocation_visible' => true,
                'birth_date' => '2001-07-22',
                'hiring_date' => '2025-02-03',
                'resignation_date' => null,
                'role' => 'User',
                'assignment_roles' => [
                    ['alias' => 'Sviluppatore Full-stack', 'expertise' => 'junior'],
                ],
            ],
        ];

        foreach ($users as $userData) {
            $roleAlias = $userData['role'];
            $assignmentRoles = $userData['assignment_roles'];

            unset($userData['role'], $userData['assignment_roles']);

            $userId = DB::table('users')->where('email', $userData['email'])->value('id');

            $userPayload = array_merge($userData, [
                'filestorage_id' => $fileStorageId,
                'password' => Hash::make('Password123!'),
                'remember_me' => false,
                'remember_token' => null,
                'updated_at' => $now,
            ]);

            if ($userId) {
                DB::table('users')->where('id', $userId)->update($userPayload);
            } else {
                $userPayload['created_at'] = $now;
                $userId = DB::table('users')->insertGetId($userPayload);
            }

            // Ruolo applicativo
            $roleId = DB::table('roles')
                ->where('alias', $roleAlias)
                ->value('id');

            if (!$roleId) {
                throw new \RuntimeException("Ruolo '{$roleAlias}' non trovato.");
            }

            DB::table('role_user')->updateOrInsert(
                ['user_id' => $userId, 'role_id' => $roleId],
                []
            );

            // Competenze professionali
            foreach ($assignmentRoles as $assignmentRoleData) {
                $assignmentRoleId = DB::table('assignmentroles')
                    ->where('alias', $assignmentRoleData['alias'])
                    ->value('id');

                if (!$assignmentRoleId) {
                    throw new \RuntimeException(
                        "Competenza '{$assignmentRoleData['alias']}' non trovata. "
                        . 'Verifica che esista in assignmentroles.'
                    );
                }

                DB::table('assignmentrole_user')->updateOrInsert(
                    [
                        'user_id' => $userId,
                        'assignmentrole_id' => $assignmentRoleId,
                    ],
                    [
                        'expertise' => $assignmentRoleData['expertise'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
}