<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserLeaveBalance;
use App\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CalendarLeave
{
    public static function hours(User $user, string $type, string $start, string $end, ?string $from, ?string $to): float
    {
        $permit = $type === 'Permesso';
        if ($permit && ($start !== $end || ! $from || ! $to || substr($from, 0, 5) >= substr($to, 0, 5))) {
            throw ValidationException::withMessages(['start_time' => 'Indica una sola data e una fascia oraria valida per il permesso.']);
        }

        $contracts = $user->contracts()->whereDate('contract_start_date', '<=', $end)
            ->where(fn ($query) => $query->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', $start))
            ->with('workingDays')->get();
        $minutes = 0;
        for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
            $active = $contracts->filter(fn ($contract) => $contract->contract_start_date->toDateString() <= $date->toDateString()
                && ($contract->contract_end_date === null || $contract->contract_end_date->toDateString() >= $date->toDateString()));
            if ($active->count() !== 1) {
                throw ValidationException::withMessages(['start_date' => 'Serve un unico contratto valido per ogni giorno richiesto.']);
            }

            $day = $active->first()->workingDays->firstWhere('day_of_week', strtolower($date->englishDayOfWeek));
            if (! $day || ($day->is_working_day && (! $day->start_time || ! $day->end_time || $day->start_time >= $day->end_time))) {
                throw ValidationException::withMessages(['start_date' => 'L’orario contrattuale non è configurato per la data richiesta.']);
            }
            if ($permit) {
                if (! $day->is_working_day || substr($from, 0, 5) < substr($day->start_time, 0, 5)
                    || substr($to, 0, 5) > substr($day->end_time, 0, 5)) {
                    throw ValidationException::withMessages(['start_time' => 'Il permesso deve rientrare nell’orario di lavoro.']);
                }

                return round(CarbonImmutable::parse($from)->diffInMinutes(CarbonImmutable::parse($to)) / 60, 2);
            }
            if ($day->is_working_day) {
                $minutes += WorkSchedule::workedMinutes($day->start_time, $day->end_time);
            }
        }
        if ($minutes <= 0) {
            throw ValidationException::withMessages(['start_date' => 'Il periodo deve includere almeno un giorno lavorativo.']);
        }

        return round($minutes / 60, 2);
    }

    public static function availableBalance(User $user, string $type, float $hours, bool $lock = false): UserLeaveBalance
    {
        $balance = $user->leaveBalances()->where('leave_type', $type === 'Ferie' ? 'ferie' : 'permesso')
            ->orderByDesc('reference_date')->orderByDesc('reference_month')->orderByDesc('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if ($balance === null || (float) $balance->remaining_hours < $hours) {
            throw ValidationException::withMessages(['balance' => $balance === null
                ? 'Non è registrato un saldo per questa tipologia.'
                : 'Le ore richieste superano il saldo disponibile.']);
        }

        return $balance;
    }
}
