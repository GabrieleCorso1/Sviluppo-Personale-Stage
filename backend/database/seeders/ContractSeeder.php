<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContractSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $contracts = [
            [
                'email' => 'mario.rossi@example.com',
                'contract_start_date' => '2020-03-01',
                'contract_end_date' => null,
                'weekly_hours' => 40,
                'contract_type' => 'indeterminato',
            ],
            [
                'email' => 'laura.bianchi@example.com',
                'contract_start_date' => '2019-06-10',
                'contract_end_date' => null,
                'weekly_hours' => 40,
                'contract_type' => 'indeterminato',
            ],
            [
                'email' => 'luca.verdi@example.com',
                'contract_start_date' => '2021-01-15',
                'contract_end_date' => null,
                'weekly_hours' => 40,
                'contract_type' => 'indeterminato',
            ],
            [
                'email' => 'giulia.neri@example.com',
                'contract_start_date' => '2022-04-04',
                'contract_end_date' => null,
                'weekly_hours' => 40,
                'contract_type' => 'indeterminato',
            ],
            [
                'email' => 'andrea.costa@example.com',
                'contract_start_date' => '2025-02-03',
                'contract_end_date' => null,
                'weekly_hours' => 24,
                'contract_type' => 'tirocinio',
            ],
        ];

        foreach ($contracts as $contractData) {
            $userId = DB::table('users')
                ->where('email', $contractData['email'])
                ->value('id');

            if (!$userId) {
                throw new \RuntimeException(
                    "Utente {$contractData['email']} non trovato. Esegui prima UserSeeder."
                );
            }

            unset($contractData['email']);

            DB::table('contracts')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'contract_start_date' => $contractData['contract_start_date'],
                ],
                array_merge($contractData, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }
}