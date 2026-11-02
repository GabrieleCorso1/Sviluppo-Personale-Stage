<?php

namespace App;

use Illuminate\Validation\ValidationException;

class WorkSchedule
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function fullTime(): array
    {
        return array_map(fn ($day, $index) => [
            'day_of_week' => $day,
            'start_time' => $index < 5 ? '09:00' : null,
            'end_time' => $index < 5 ? '18:00' : null,
            'is_working_day' => $index < 5,
        ], self::DAYS, array_keys(self::DAYS));
    }

    public static function validate(array $days, int $weeklyHours): array
    {
        $byDay = [];
        $totalMinutes = 0;
        foreach ($days as $index => $day) {
            $working = (bool) ($day['is_working_day'] ?? false);
            $start = $working ? substr($day['start_time'] ?? '', 0, 5) : null;
            $end = $working ? substr($day['end_time'] ?? '', 0, 5) : null;
            if ($working) {
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
                    throw ValidationException::withMessages(['working_days.'.$index.'.start_time' => 'Inserisci inizio e fine per ogni giorno lavorativo.']);
                }
                $minutes = self::minutes($end) - self::minutes($start);
                if ($minutes <= 0) {
                    throw ValidationException::withMessages(['working_days.'.$index.'.end_time' => 'La fine deve essere successiva all’inizio.']);
                }
                $totalMinutes += self::workedMinutes($start, $end);
            }
            $byDay[$day['day_of_week']] = [
                'day_of_week' => $day['day_of_week'], 'start_time' => $start,
                'end_time' => $end, 'is_working_day' => $working,
            ];
        }
        $normalized = array_map(fn ($day) => $byDay[$day] ?? [
            'day_of_week' => $day, 'start_time' => null, 'end_time' => null, 'is_working_day' => false,
        ], self::DAYS);

        if ($weeklyHours === 40 && $normalized !== self::fullTime()) {
            throw ValidationException::withMessages(['working_days' => 'Per 40 ore si applica l’orario fisso lunedì–venerdì 09:00–18:00, con un’ora di pausa.']);
        }
        if ($totalMinutes !== $weeklyHours * 60) {
            throw ValidationException::withMessages(['working_days' => 'Il totale degli orari deve corrispondere alle '.$weeklyHours.' ore settimanali del contratto.']);
        }

        return $normalized;
    }

    public static function workedMinutes(string $start, string $end): int
    {
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        return self::minutes($end) - self::minutes($start) - ($start === '09:00' && $end === '18:00' ? 60 : 0);
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
