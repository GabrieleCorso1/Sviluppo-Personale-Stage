<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Recupera gli utenti responsabili
        $userIds = DB::table('users')
            ->whereIn('email', [
                'mario.rossi@example.com',
                'laura.bianchi@example.com',
                'luca.verdi@example.com',
                'giulia.neri@example.com',
                'andrea.costa@example.com',
            ])
            ->pluck('id', 'email');

        // Recupera il file associato ai progetti
        $fileStorageId = DB::table('filestorage')
            ->where('name', 'default-project')
            ->value('id');

        if (!$fileStorageId) {
            throw new \RuntimeException(
                'File default-project non trovato. Esegui prima FileStorageSeeder.'
            );
        }

        foreach ([
            'mario.rossi@example.com',
            'laura.bianchi@example.com',
            'luca.verdi@example.com',
            'giulia.neri@example.com',
        ] as $email) {
            if (!$userIds->has($email)) {
                throw new \RuntimeException(
                    "Utente {$email} non trovato. Esegui prima UserSeeder."
                );
            }
        }

        // 1. Progetti
        $projects = [
            [
                'alias' => 'Portale Clienti',
                'description' => 'Sviluppo del portale web per i clienti.',
                'is_daily' => true,
                'is_azure' => true,
                'is_ux_ui' => true,
                'status' => 'in corso',
                'azure_project_name' => 'Portale Clienti',
                'project_manager_email' => 'laura.bianchi@example.com',
                'team_leader_email' => 'luca.verdi@example.com',
                'scrum_master_email' => 'luca.verdi@example.com',
                'delivery_plan' => 'Rilascio incrementale mensile',
                'azure_team_name' => 'Team Portale',
                'bug_count_url' => 'https://example.com/portale/bugs',
                'note' => 'Progetto dimostrativo',
                'priority' => 'alta',
                'pbi_without_feature_url' => null,
                'pbi_past_feature_url' => null,
                'is_timesheet' => true,
                'is_course' => false,
                'start_date' => '2026-01-12',
                'end_date' => null,
            ],
            [
                'alias' => 'API Aziendali',
                'description' => 'Sviluppo e manutenzione delle API aziendali.',
                'is_daily' => true,
                'is_azure' => true,
                'is_ux_ui' => false,
                'status' => 'in corso',
                'azure_project_name' => 'API Aziendali',
                'project_manager_email' => 'laura.bianchi@example.com',
                'team_leader_email' => 'luca.verdi@example.com',
                'scrum_master_email' => 'luca.verdi@example.com',
                'delivery_plan' => 'Rilascio per sprint',
                'azure_team_name' => 'Team API',
                'bug_count_url' => 'https://example.com/api/bugs',
                'note' => 'Progetto dimostrativo',
                'priority' => 'media',
                'pbi_without_feature_url' => null,
                'pbi_past_feature_url' => null,
                'is_timesheet' => true,
                'is_course' => false,
                'start_date' => '2026-02-02',
                'end_date' => null,
            ],
        ];

        $projectIds = [];

        foreach ($projects as $projectData) {
            $managerId = $userIds[$projectData['project_manager_email']];
            $leaderId = $userIds[$projectData['team_leader_email']];
            $scrumId = $userIds[$projectData['scrum_master_email']];

            unset(
                $projectData['project_manager_email'],
                $projectData['team_leader_email'],
                $projectData['scrum_master_email']
            );

            $alias = $projectData['alias'];

            DB::table('projects')->updateOrInsert(
                ['alias' => $alias],
                array_merge($projectData, [
                    'project_manager_id' => $managerId,
                    'team_leader_id' => $leaderId,
                    'scrum_master_id' => $scrumId,
                    'filestorage_id' => $fileStorageId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );

            $projectIds[$alias] = DB::table('projects')
                ->where('alias', $alias)
                ->value('id');
        }

        // 2. Necessità di competenze per progetto
        $necessities = [
            'Portale Clienti' => [
                'Sviluppatore Front-end',
                'Sviluppatore Back-end',
                'UX-UI Designer',
            ],
            'API Aziendali' => [
                'Sviluppatore Back-end',
                'Sviluppatore Full-stack',
            ],
        ];

        foreach ($necessities as $projectAlias => $assignmentAliases) {
            foreach ($assignmentAliases as $assignmentAlias) {
                $assignmentRoleId = DB::table('assignmentroles')
                    ->where('alias', $assignmentAlias)
                    ->value('id');

                if (!$assignmentRoleId) {
                    throw new \RuntimeException(
                        "Competenza '{$assignmentAlias}' non trovata."
                    );
                }

                DB::table('necessities')->updateOrInsert(
                    [
                        'project_id' => $projectIds[$projectAlias],
                        'assignmentrole_id' => $assignmentRoleId,
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        // 3. Assegnazioni delle persone ai progetti
        $assignments = [
            [
                'project' => 'Portale Clienti',
                'email' => 'luca.verdi@example.com',
                'role' => 'Sviluppatore Back-end',
                'startwork_date' => '2026-01-12',
                'endwork_date' => null,
                'work_allocation' => 50,
            ],
            [
                'project' => 'Portale Clienti',
                'email' => 'giulia.neri@example.com',
                'role' => 'Sviluppatore Front-end',
                'startwork_date' => '2026-01-12',
                'endwork_date' => null,
                'work_allocation' => 70,
            ],
            [
                'project' => 'API Aziendali',
                'email' => 'luca.verdi@example.com',
                'role' => 'Sviluppatore Back-end',
                'startwork_date' => '2026-02-02',
                'endwork_date' => null,
                'work_allocation' => 50,
            ],
            [
                'project' => 'API Aziendali',
                'email' => 'andrea.costa@example.com',
                'role' => 'Sviluppatore Full-stack',
                'startwork_date' => '2026-02-02',
                'endwork_date' => null,
                'work_allocation' => 50,
            ],
        ];

        foreach ($assignments as $assignment) {
            $userId = $userIds[$assignment['email']] ?? null;

            if (!$userId) {
                throw new \RuntimeException(
                    "Utente {$assignment['email']} non trovato."
                );
            }

            $assignmentRoleId = DB::table('assignmentroles')
                ->where('alias', $assignment['role'])
                ->value('id');

            if (!$assignmentRoleId) {
                throw new \RuntimeException(
                    "Competenza '{$assignment['role']}' non trovata."
                );
            }

            DB::table('project_user')->updateOrInsert(
                [
                    'project_id' => $projectIds[$assignment['project']],
                    'user_id' => $userId,
                ],
                [
                    'assignmentrole_id' => $assignmentRoleId,
                    'startwork_date' => $assignment['startwork_date'],
                    'endwork_date' => $assignment['endwork_date'],
                    'work_allocation' => $assignment['work_allocation'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}