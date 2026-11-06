<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserLeavesRequest;
use App\Models\LeaveAuditLog;
use App\Models\User;
use App\Support\ProjectCalendar;
use App\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserLeaveController extends Controller
{
    public function index(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageLeaves', $user);
        $currentYear = Carbon::parse(ProjectCalendar::today())->year;
        $query = $request->validate(['year' => ['sometimes', 'integer', 'min:1900', 'max:'.$currentYear]]);
        $year = (int) ($query['year'] ?? $currentYear);
        $balances = $user->leaveBalances()->whereBetween('reference_month', [$year.'-01-01', $year.'-12-31'])
            ->orderBy('reference_month')->orderBy('leave_type')->get();
        $contracts = $user->contracts()->whereDate('contract_start_date', '<=', $year.'-12-31')
            ->where(fn ($query) => $query->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', $year.'-01-01'))->get();
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = sprintf('%04d-%02d-01', $year, $month);
            $values = [];
            foreach (['ferie', 'permesso', 'rol'] as $type) {
                $values[$type] = $balances->first(fn ($balance) => $balance->reference_month->format('Y-m') === substr($date, 0, 7) && $balance->leave_type === $type);
            }
            $monthStart = Carbon::parse($date)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
            $matching = $contracts->filter(fn ($contract) => $contract->contract_start_date->lte($monthEnd)
                && ($contract->contract_end_date === null || $contract->contract_end_date->gte($monthStart)));
            $contract = $matching->count() === 1 ? $matching->first() : null;
            $fullTimeMonth = $contract !== null && $contract->weekly_hours === 40
                && $contract->contract_start_date->lte($monthStart)
                && ($contract->contract_end_date === null || $contract->contract_end_date->gte($monthEnd));
            $vacation = $values['ferie'];
            $days = $fullTimeMonth && $vacation !== null ? [
                'remaining_days' => round((float) $vacation->remaining_hours / 8, 4),
                'accrued_days' => round((float) $vacation->accrued_hours / 8, 4),
                'monthly_accrual_days' => round((float) $vacation->monthly_accrual / 8, 4),
            ] : null;
            $months[] = ['reference_month' => $date, 'balances' => $values, 'full_time_vacation_days' => $days];
        }

        return response()->json([
            'success' => true, 'year' => $year, 'unit' => 'hours', 'data' => $balances, 'months' => $months,
            'rol_entitlements' => $contracts->map(fn ($contract) => [
                'contract_id' => $contract->id,
                'contract_start_date' => $contract->contract_start_date->toDateString(),
                'contract_end_date' => $contract->contract_end_date?->toDateString(),
                'has_rol' => $contract->has_rol,
            ])->values(),
        ]);
    }

    public function update(UpdateUserLeavesRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            foreach ($request->validated('leaves') as $index => $entry) {
                $month = Carbon::parse($entry['reference_month'])->startOfMonth()->toDateString();
                $balance = $user->leaveBalances()->where('leave_type', $entry['leave_type'])->whereDate('reference_month', $month)->first()
                    ?? $user->leaveBalances()->make(['leave_type' => $entry['leave_type'], 'reference_month' => $month]);
                $previousReferenceDate = $balance->reference_date?->toDateString();
                $referenceDate = $entry['leave_type'] === 'ferie'
                    ? null
                    : ($entry['reference_date'] ?? $previousReferenceDate ?? $entry['reference_month']);
                if ($entry['leave_type'] === 'rol') {
                    $contracts = $user->contracts()
                        ->whereDate('contract_start_date', '<=', $referenceDate)
                        ->where(fn ($query) => $query->whereNull('contract_end_date')
                            ->orWhereDate('contract_end_date', '>=', $referenceDate))
                        ->lockForUpdate()->get();
                    if ($contracts->count() !== 1 || ! $contracts->first()->has_rol) {
                        throw ValidationException::withMessages([
                            'leaves.'.$index.'.leave_type' => 'Per aggiornare i R.O.L. serve un unico contratto valido alla data di riferimento con diritto ai R.O.L.',
                        ]);
                    }
                }
                $balance->reference_date = $referenceDate;
                $changes = [];
                foreach (['remaining_hours', 'accrued_hours', 'monthly_accrual'] as $field) {
                    $old = $balance->exists ? $balance->getAttribute($field) : null;
                    if (array_key_exists($field, $entry)) {
                        $balance->setAttribute($field, $entry[$field]);
                    } elseif (! $balance->exists) {
                        $balance->setAttribute($field, 0);
                    }
                    $new = $balance->getAttribute($field);
                    if ($old !== $new) {
                        $changes[] = ['field_name' => $field, 'old_value' => $old, 'new_value' => $new];
                    }
                }
                if ($changes === [] && $previousReferenceDate !== $referenceDate) {
                    $changes[] = ['field_name' => 'reference_date', 'old_value' => null, 'new_value' => null];
                }
                $balance->save();
                foreach ($changes as $change) {
                    LeaveAuditLog::create($change + [
                        'user_id' => $user->id, 'admin_id' => $request->user()->id,
                        'leave_type' => $entry['leave_type'], 'reference_month' => $month,
                        'reference_date' => $referenceDate, 'previous_reference_date' => $previousReferenceDate,
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Contatori aggiornati con successo.', 'data' => $user->fresh('leaveBalances')]);
    }

    public function history(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageLeaves', $user);
        $currentYear = Carbon::parse(ProjectCalendar::today())->year;
        $filters = $request->validate([
            'year' => ['sometimes', 'integer', 'min:1900', 'max:'.$currentYear],
            'leave_type' => ['sometimes', 'in:ferie,permesso,rol'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $logs = LeaveAuditLog::where('user_id', $user->id)->with(['admin' => fn ($query) => $query->withTrashed()->select('id', 'first_name', 'last_name')]);
        if (isset($filters['year'])) {
            $logs->whereYear('reference_month', $filters['year']);
        }
        if (isset($filters['leave_type'])) {
            $logs->where('leave_type', $filters['leave_type']);
        }

        return response()->json(['success' => true, 'data' => $logs->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1))->appends($filters)]);
    }

    public function conversion(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageLeaves', $user);
        $input = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'contract_id' => ['sometimes', 'required', 'integer'],
            'hours' => ['required_without:days', 'prohibits:days', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'days' => ['required_without:hours', 'prohibits:hours', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ]);
        $date = Carbon::parse($input['date']);
        $contracts = $user->contracts()->whereDate('contract_start_date', '<=', $input['date'])
            ->where(fn ($query) => $query->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', $input['date']));
        if (isset($input['contract_id'])) {
            $contracts->whereKey($input['contract_id']);
        }
        $matches = $contracts->with('workingDays')->limit(2)->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['contract_id' => 'Seleziona un unico contratto dell’utente valido alla data indicata.']);
        }
        $contract = $matches->first();
        if ($date->isWeekend()) {
            throw ValidationException::withMessages(['date' => 'La data indicata non è un giorno lavorativo.']);
        }
        $hoursPerDay = 8.0;
        if ($contract->weekly_hours !== 40) {
            $schedule = WorkSchedule::validate($contract->workingDays->toArray(), $contract->weekly_hours);
            $day = $schedule[$date->dayOfWeekIso - 1];
            if (! $day['is_working_day']) {
                throw ValidationException::withMessages(['date' => 'La data indicata non è un giorno lavorativo.']);
            }
            $hoursPerDay = WorkSchedule::workedMinutes($day['start_time'], $day['end_time']) / 60;
        }
        $hours = isset($input['hours']) ? (float) $input['hours'] : (float) $input['days'] * $hoursPerDay;
        $days = isset($input['days']) ? (float) $input['days'] : (float) $input['hours'] / $hoursPerDay;

        return response()->json(['success' => true, 'data' => [
            'date' => $input['date'], 'contract_id' => $contract->id, 'hours_per_day' => $hoursPerDay,
            'hours' => round($hours, 2), 'days' => round($days, 4),
        ]]);
    }
}
