<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\ContractWorkingDay;
use Illuminate\Database\Seeder;

class ContractWorkingDaySeeder extends Seeder
{
    public function run(): void
    {
        $days = [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
            'sunday',
        ];

        foreach (Contract::all() as $contract) {

            // Distribuzione dimostrativa in base alle ore settimanali.
            $schedule = match ((int) $contract->weekly_hours) {
                40 => [
                    'monday' => ['09:00', '18:00'],
                    'tuesday' => ['09:00', '18:00'],
                    'wednesday' => ['09:00', '18:00'],
                    'thursday' => ['09:00', '18:00'],
                    'friday' => ['09:00', '18:00'],
                ],

                24 => [
                    'monday' => ['09:00', '13:00'],
                    'tuesday' => ['09:00', '13:00'],
                    'wednesday' => ['09:00', '13:00'],
                    'thursday' => ['09:00', '13:00'],
                    'friday' => ['09:00', '13:00'],
                    'saturday' => ['09:00', '13:00'],
                ],

                20 => [
                    'monday' => ['09:00', '13:00'],
                    'tuesday' => ['09:00', '13:00'],
                    'wednesday' => ['09:00', '13:00'],
                    'thursday' => ['09:00', '13:00'],
                    'friday' => ['09:00', '13:00'],
                ],

                default => null,
            };

            if ($schedule === null) {
                $this->command?->warn(
                    "Contratto {$contract->id}: distribuzione non definita per {$contract->weekly_hours} ore."
                );

                continue;
            }

            foreach ($days as $day) {
                $hours = $schedule[$day] ?? null;

                ContractWorkingDay::updateOrCreate(
                    [
                        'contract_id' => $contract->id,
                        'day_of_week' => $day,
                    ],
                    [
                        'start_time' => $hours[0] ?? null,
                        'end_time' => $hours[1] ?? null,
                        'is_working_day' => $hours !== null,
                    ]
                );
            }
        }
    }
}
