<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CalendarSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Tipologie di calendario
        $typologies = [
            [
                'alias' => 'Ferie',
            ],
            [
                'alias' => 'Permesso',
            ],
            [
                'alias' => 'Smart Working',
            ],
            [
                'alias' => 'Malattia',
            ],
        ];

        foreach ($typologies as $typology) {
            DB::table('calendar_typology')->updateOrInsert(
                ['alias' => $typology['alias']],
                [
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // Recupera gli ID delle tipologie
        $typologyIds = DB::table('calendar_typology')
            ->whereIn('alias', array_column($typologies, 'alias'))
            ->pluck('id', 'alias');

        // 2. Recupera gli utenti
        $users = DB::table('users')
            ->whereIn('email', [
                'mario.rossi@example.com',
                'laura.bianchi@example.com',
                'luca.verdi@example.com',
                'giulia.neri@example.com',
                'andrea.costa@example.com',
            ])
            ->pluck('id', 'email');

        $calendarEntries = [
            [
                'email' => 'giulia.neri@example.com',
                'typology' => 'Ferie',
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-03',
                'start_time' => null,
                'end_time' => null,
                'status' => 'approved',
                'is_smart' => false,
                'notes' => 'Richiesta ferie dimostrativa',
            ],
            [
                'email' => 'luca.verdi@example.com',
                'typology' => 'Permesso',
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-10',
                'start_time' => '14:00:00',
                'end_time' => '16:00:00',
                'status' => 'pending',
                'is_smart' => false,
                'notes' => 'Richiesta permesso dimostrativa',
            ],
            [
                'email' => 'laura.bianchi@example.com',
                'typology' => 'Smart Working',
                'start_date' => '2026-11-12',
                'end_date' => '2026-11-12',
                'start_time' => null,
                'end_time' => null,
                'status' => 'approved',
                'is_smart' => true,
                'notes' => 'Giornata di lavoro da remoto',
            ],
            [
                'email' => 'andrea.costa@example.com',
                'typology' => 'Ferie',
                'start_date' => '2026-12-28',
                'end_date' => '2026-12-28',
                'start_time' => null,
                'end_time' => null,
                'status' => 'pending',
                'is_smart' => false,
                'notes' => 'Richiesta ferie dimostrativa',
            ],
            [
                'email' => 'mario.rossi@example.com',
                'typology' => 'Malattia',
                'start_date' => '2026-10-15',
                'end_date' => '2026-10-16',
                'start_time' => null,
                'end_time' => null,
                'status' => 'approved',
                'is_smart' => false,
                'notes' => 'Record dimostrativo',
            ],
        ];

        foreach ($calendarEntries as $entry) {
            $userId = $users[$entry['email']] ?? null;

            if (!$userId) {
                throw new \RuntimeException(
                    "Utente {$entry['email']} non trovato. Esegui prima UserSeeder."
                );
            }

            $typologyId = $typologyIds[$entry['typology']] ?? null;

            if (!$typologyId) {
                throw new \RuntimeException(
                    "Tipologia '{$entry['typology']}' non trovata."
                );
            }

            DB::table('calendar')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'typology_id' => $typologyId,
                    'start_date' => $entry['start_date'],
                    'end_date' => $entry['end_date'],
                    'start_time' => $entry['start_time'],
                ],
                [
                    'end_time' => $entry['end_time'],
                    'status' => $entry['status'],
                    'is_smart' => $entry['is_smart'],
                    'notes' => $entry['notes'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}