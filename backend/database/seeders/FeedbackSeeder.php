<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeedbackSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Recupera gli utenti
        $users = DB::table('users')
            ->whereIn('email', [
                'mario.rossi@example.com',
                'laura.bianchi@example.com',
                'luca.verdi@example.com',
                'giulia.neri@example.com',
                'andrea.costa@example.com',
            ])
            ->pluck('id', 'email');

        foreach ([
            'mario.rossi@example.com',
            'laura.bianchi@example.com',
            'luca.verdi@example.com',
            'giulia.neri@example.com',
            'andrea.costa@example.com',
        ] as $email) {
            if (!$users->has($email)) {
                throw new \RuntimeException(
                    "Utente {$email} non trovato. Esegui prima UserSeeder."
                );
            }
        }

        // 2. Feedback SBI
        $feedbacks = [
            [
                'writer' => 'laura.bianchi@example.com',
                'receiver' => 'luca.verdi@example.com',
                'is_positive' => true,
                'situation' => 'Durante la pianificazione dello sprint.',
                'behavior' => 'Hai aiutato il team a chiarire le attività tecniche.',
                'impact' => 'La pianificazione è stata più efficace.',
                'suggestion' => 'Continua a condividere le tue competenze.',
                'written_at' => '2026-10-05',
                'answered_at' => '2026-10-06',
            ],
            [
                'writer' => 'luca.verdi@example.com',
                'receiver' => 'giulia.neri@example.com',
                'is_positive' => true,
                'situation' => 'Durante lo sviluppo del portale clienti.',
                'behavior' => 'Hai condiviso aggiornamenti chiari sul lavoro svolto.',
                'impact' => 'Il team ha potuto coordinarsi meglio.',
                'suggestion' => 'Mantieni questa comunicazione.',
                'written_at' => '2026-10-08',
                'answered_at' => null,
            ],
            [
                'writer' => 'mario.rossi@example.com',
                'receiver' => 'andrea.costa@example.com',
                'is_positive' => false,
                'situation' => 'Durante la revisione di una funzionalità.',
                'behavior' => 'Alcuni aggiornamenti sono arrivati in ritardo.',
                'impact' => 'La revisione ha richiesto più tempo.',
                'suggestion' => 'Segnala prima eventuali blocchi.',
                'written_at' => '2026-10-10',
                'answered_at' => null,
            ],
        ];

        foreach ($feedbacks as $feedback) {
            $writerId = $users[$feedback['writer']];
            $receiverId = $users[$feedback['receiver']];

            unset($feedback['writer'], $feedback['receiver']);

            DB::table('sbis')->updateOrInsert(
                [
                    'writer_id' => $writerId,
                    'receiver_id' => $receiverId,
                    'written_at' => $feedback['written_at'],
                    'situation' => $feedback['situation'],
                ],
                array_merge($feedback, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }

        // 3. Bilanci mensili delle ore residue
        $leaveBalances = [
            [
                'email' => 'mario.rossi@example.com',
                'leave_type' => 'ferie',
                'reference_month' => '2026-10-01',
                'remaining_hours' => 120.00,
                'accrued_hours' => 160.00,
                'monthly_accrual' => 13.33,
            ],
            [
                'email' => 'laura.bianchi@example.com',
                'leave_type' => 'ferie',
                'reference_month' => '2026-10-01',
                'remaining_hours' => 96.00,
                'accrued_hours' => 136.00,
                'monthly_accrual' => 13.33,
            ],
            [
                'email' => 'luca.verdi@example.com',
                'leave_type' => 'permesso',
                'reference_month' => '2026-10-01',
                'remaining_hours' => 24.00,
                'accrued_hours' => 40.00,
                'monthly_accrual' => 4.00,
            ],
            [
                'email' => 'giulia.neri@example.com',
                'leave_type' => 'ferie',
                'reference_month' => '2026-10-01',
                'remaining_hours' => 80.00,
                'accrued_hours' => 120.00,
                'monthly_accrual' => 13.33,
            ],
            [
                'email' => 'andrea.costa@example.com',
                'leave_type' => 'ferie',
                'reference_month' => '2026-10-01',
                'remaining_hours' => 32.00,
                'accrued_hours' => 48.00,
                'monthly_accrual' => 8.00,
            ],
        ];

        foreach ($leaveBalances as $balance) {
            $userId = $users[$balance['email']];

            unset($balance['email']);

            DB::table('user_leave_balances')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'leave_type' => $balance['leave_type'],
                    'reference_month' => $balance['reference_month'],
                ],
                array_merge($balance, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }
    }
}