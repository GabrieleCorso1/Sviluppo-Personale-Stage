<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SprintSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $projects = DB::table('projects')
            ->where('is_course', false)->where('is_timesheet', false)->whereNull('deleted_at')
            ->whereIn('alias', ['Portale Clienti', 'API Aziendali'])
            ->pluck('id', 'alias');

        $categories = DB::table('teamcategories')
            ->whereIn('alias', [
                'Web Development',
                'Backend Services',
                'UX/UI Design',
            ])
            ->pluck('id', 'alias');

        foreach (['Portale Clienti', 'API Aziendali'] as $alias) {
            if (! $projects->has($alias)) {
                throw new \RuntimeException(
                    "Progetto '{$alias}' non trovato. Esegui prima ProjectSeeder."
                );
            }
        }

        foreach ([
            'Web Development',
            'Backend Services',
            'UX/UI Design',
        ] as $alias) {
            if (! $categories->has($alias)) {
                throw new \RuntimeException(
                    "Categoria '{$alias}' non trovata. Esegui prima TeamCategorySeeder."
                );
            }
        }

        $sprints = [
            [
                'project' => 'Portale Clienti',
                'teamcategory' => 'Web Development',
                'number' => 1,
                'start_date' => '2026-01-12',
                'end_date' => '2026-01-25',
                'is_concluded' => true,
                'is_pilot' => false,
                'is_billing' => true,
                'has_csv' => false,
                'is_planningdone' => true,
                'is_estimatedone' => true,
                'is_reviewdone' => true,
            ],
            [
                'project' => 'Portale Clienti',
                'teamcategory' => 'UX/UI Design',
                'number' => 2,
                'start_date' => '2026-01-26',
                'end_date' => '2026-02-08',
                'is_concluded' => true,
                'is_pilot' => false,
                'is_billing' => true,
                'has_csv' => false,
                'is_planningdone' => true,
                'is_estimatedone' => true,
                'is_reviewdone' => true,
            ],
            [
                'project' => 'API Aziendali',
                'teamcategory' => 'Backend Services',
                'number' => 1,
                'start_date' => '2026-02-02',
                'end_date' => '2026-02-15',
                'is_concluded' => true,
                'is_pilot' => true,
                'is_billing' => false,
                'has_csv' => false,
                'is_planningdone' => true,
                'is_estimatedone' => true,
                'is_reviewdone' => false,
            ],
            [
                'project' => 'API Aziendali',
                'teamcategory' => 'Backend Services',
                'number' => 2,
                'start_date' => '2026-02-16',
                'end_date' => '2026-03-01',
                'is_concluded' => false,
                'is_pilot' => false,
                'is_billing' => false,
                'has_csv' => false,
                'is_planningdone' => true,
                'is_estimatedone' => false,
                'is_reviewdone' => false,
            ],
        ];

        foreach ($sprints as $sprint) {
            $projectId = $projects[$sprint['project']];
            $teamCategoryId = $categories[$sprint['teamcategory']];

            unset($sprint['project'], $sprint['teamcategory']);

            DB::table('sprints')->updateOrInsert(
                [
                    'project_id' => $projectId,
                    'number' => $sprint['number'],
                ],
                array_merge($sprint, [
                    'teamcategory_id' => $teamCategoryId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }
}
