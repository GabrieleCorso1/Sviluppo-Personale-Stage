<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssignmentRoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $assignmentRoles = [
            [
                'alias' => 'Sviluppatore Full-stack',
                'description' => 'Sviluppo sia front-end sia back-end.',
            ],
            [
                'alias' => 'Sviluppatore Front-end',
                'description' => 'Sviluppo dell’interfaccia utente.',
            ],
            [
                'alias' => 'Sviluppatore Back-end',
                'description' => 'Sviluppo della logica applicativa e dei servizi.',
            ],
            [
                'alias' => 'UX-UI Designer',
                'description' => 'Progettazione dell’esperienza e dell’interfaccia utente.',
            ],
            [
                'alias' => 'Scrum Master',
                'description' => 'Facilitazione dei processi Scrum.',
            ],
            [
                'alias' => 'Project Manager',
                'description' => 'Pianificazione e gestione dei progetti.',
            ],
            [
                'alias' => 'Team Leader',
                'description' => 'Coordinamento tecnico del team.',
            ],
        ];

        foreach ($assignmentRoles as $assignmentRole) {
            DB::table('assignmentroles')->updateOrInsert(
                ['alias' => $assignmentRole['alias']],
                [
                    'description' => $assignmentRole['description'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}