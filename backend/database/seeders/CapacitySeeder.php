<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CapacitySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $users = DB::table('users')
            ->whereIn('email', [
                'luca.verdi@example.com',
                'giulia.neri@example.com',
                'andrea.costa@example.com',
            ])
            ->pluck('id', 'email');

        $projects = DB::table('projects')
            ->where('is_course', false)->where('is_timesheet', false)->whereNull('deleted_at')
            ->whereIn('alias', ['Portale Clienti', 'API Aziendali'])
            ->pluck('id', 'alias');

        if (! $users->has('luca.verdi@example.com')
            || ! $users->has('giulia.neri@example.com')
            || ! $users->has('andrea.costa@example.com')) {
            throw new \RuntimeException(
                'Uno o più utenti non sono presenti. Esegui prima UserSeeder.'
            );
        }

        if (! $projects->has('Portale Clienti')
            || ! $projects->has('API Aziendali')) {
            throw new \RuntimeException(
                'Uno o più progetti non sono presenti. Esegui prima ProjectSeeder.'
            );
        }

        $sprints = DB::table('sprints')
            ->whereIn('project_id', $projects->values())
            ->get()
            ->keyBy(fn ($sprint) => $sprint->project_id.'-'.$sprint->number);

        $capacities = [
            [
                'email' => 'luca.verdi@example.com',
                'project' => 'Portale Clienti',
                'sprint_number' => 1,
                'date' => '2026-01-12',
                'value' => 6.00,
                'billing_value' => 6.00,
                'alias' => 'Sviluppo backend',
                'is_capacity_per_day' => true,
                'is_statistical' => false,
                'is_dailydone' => true,
                'note' => 'Capacità giornaliera dimostrativa',
                'request_status' => 'approved',
            ],
            [
                'email' => 'giulia.neri@example.com',
                'project' => 'Portale Clienti',
                'sprint_number' => 1,
                'date' => '2026-01-12',
                'value' => 5.50,
                'billing_value' => 5.50,
                'alias' => 'Sviluppo frontend',
                'is_capacity_per_day' => true,
                'is_statistical' => false,
                'is_dailydone' => true,
                'note' => 'Capacità giornaliera dimostrativa',
                'request_status' => 'approved',
            ],
            [
                'email' => 'luca.verdi@example.com',
                'project' => 'API Aziendali',
                'sprint_number' => 1,
                'date' => '2026-02-02',
                'value' => 6.00,
                'billing_value' => 5.00,
                'alias' => 'Sviluppo API',
                'is_capacity_per_day' => true,
                'is_statistical' => false,
                'is_dailydone' => true,
                'note' => 'Capacità giornaliera dimostrativa',
                'request_status' => 'approved',
            ],
            [
                'email' => 'andrea.costa@example.com',
                'project' => 'API Aziendali',
                'sprint_number' => 1,
                'date' => '2026-02-02',
                'value' => 4.00,
                'billing_value' => null,
                'alias' => 'Affiancamento e sviluppo',
                'is_capacity_per_day' => true,
                'is_statistical' => false,
                'is_dailydone' => false,
                'note' => 'Capacità dimostrativa del tirocinante',
                'request_status' => 'pending',
            ],
        ];

        foreach ($capacities as $capacity) {
            $userId = $users[$capacity['email']];
            $projectId = $projects[$capacity['project']];
            $sprintKey = $projectId.'-'.$capacity['sprint_number'];
            $sprint = $sprints->get($sprintKey);

            if (! $sprint) {
                throw new \RuntimeException(
                    "Sprint {$capacity['sprint_number']} non trovato per "
                    ."il progetto '{$capacity['project']}'. Esegui prima SprintSeeder."
                );
            }

            unset(
                $capacity['email'],
                $capacity['project'],
                $capacity['sprint_number']
            );

            DB::table('capacities')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'sprint_id' => $sprint->id,
                    'date' => $capacity['date'],
                    'alias' => $capacity['alias'],
                ],
                array_merge($capacity, [
                    'sprint_id' => $sprint->id,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }
}
