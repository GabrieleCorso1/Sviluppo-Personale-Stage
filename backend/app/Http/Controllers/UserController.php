<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\AssignmentRole;
use App\Models\Role;
use App\Models\User;
use App\UserInvitation;
use App\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(IndexUserRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = User::with(['roles', 'assignmentRoles', 'contracts.workingDays']);

        if (($filters['status'] ?? 'active') === 'disabled') {
            $query->onlyTrashed();
        }

        // Each word must match a name or email field, including full-name searches.
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->where(function ($query) use ($term) {
                $query->where('first_name', 'like', '%'.$term.'%')
                    ->orWhere('last_name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (isset($filters['role_id'])) {
            $query->whereHas('roles', fn ($query) => $query->where('roles.id', $filters['role_id']));
        }

        if (isset($filters['assignment_role_id']) || isset($filters['expertise'])) {
            $query->whereHas('assignmentRoles', function ($query) use ($filters) {
                if (isset($filters['assignment_role_id'])) {
                    $query->where('assignmentroles.id', $filters['assignment_role_id']);
                }
                if (isset($filters['expertise'])) {
                    $query->where('assignmentrole_user.expertise', $filters['expertise']);
                }
            });
        }

        foreach (['isAllocation_visible', 'is_intern', 'gender'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $direction = $filters['sort_direction'] ?? 'asc';
        $sort = $filters['sort_by'] ?? 'name';
        if ($sort === 'name') {
            $query->orderBy('first_name', $direction)->orderBy('last_name', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $users = $query->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($filters);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json([
            'success' => true,
            'data' => $user->load(['roles', 'assignmentRoles', 'contracts.workingDays', 'filestorage']),
        ]);
    }

    public function options(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json([
            'success' => true,
            'data' => [
                'roles' => Role::orderBy('alias')->get(['id', 'alias']),
                'assignment_roles' => AssignmentRole::orderBy('alias')->get(['id', 'alias']),
                'expertise' => ['junior', 'senior'],
            ],
        ]);
    }

    /**
     * Mostra i dati dell'utente attualmente autenticato usando la facciata Auth.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => $user,
        ], 200);
    }

    /**
     * Elimina un utente specifico tramite il suo ID.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        return $this->disable($request, $id);
    }

    public function store(StoreUserRequest $request, UserInvitation $invitations): JsonResponse
    {
        $result = DB::transaction(function () use ($request) {

            // 1. Creazione dell'utente
            $user = User::create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'email' => $request->validated('email'),
                'azure_email' => $request->validated('azure_email'),
                'company_email' => $request->validated('company_email'),
                'password' => Str::random(64),
                'gender' => $request->validated('gender'),
                'birth_date' => $request->validated('birth_date'),
                'hiring_date' => $request->validated('hiring_date'),
                'filestorage_id' => $request->validated('filestorage_id'),
                'is_intern' => $request->validated('is_intern', false),
                'isAllocation_visible' => $request->validated('isAllocation_visible', true),
                'remember_me' => $request->validated('remember_me', false),
            ]);

            $this->saveUserContract($user, $request->validated());

            // A hiring-date change must remain compatible with every saved contract.
            if ($request->has('hiring_date') && $user->contracts()
                ->whereDate('contract_start_date', '<', $user->hiring_date->toDateString())->exists()) {
                throw ValidationException::withMessages([
                    'hiring_date' => 'La data di assunzione non può essere successiva all’inizio di un contratto esistente.',
                ]);
            }

            $this->syncUserAssignments($user, $request->validated());

            return $user;
        });

        $invitation = $invitations->send($result);

        // Ricarichiamo le relazioni includendo anche i giorni lavorativi nella risposta
        $result->load(['roles', 'assignmentRoles', 'contracts.workingDays']);

        return response()->json([
            'message' => $invitation['status'] === 'sent'
                ? 'Utente creato. Invito per impostare la password inviato.'
                : 'Utente creato, ma invito non inviato. Richiedere un nuovo link dalla pagina Password dimenticata.',
            'invitation' => $invitation,
            'data' => $result,
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $user) {

            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            // 1. Prepariamo i dati dell'utente da aggiornare
            $userData = $request->safe()->only($user->getFillable());

            $passwordChanged = ! empty($userData['password']);
            if ($passwordChanged) {
                $userData['password'] = bcrypt($userData['password']);
            } else {
                unset($userData['password']);
            }

            // Aggiorniamo l'utente
            $user->update($userData);

            if ($passwordChanged) {
                $user->tokens()->delete();
            }

            $this->saveUserContract($user, $request->validated());

            // A hiring-date change must remain compatible with every saved contract.
            if ($request->has('hiring_date') && $user->contracts()
                ->whereDate('contract_start_date', '<', $user->hiring_date->toDateString())->exists()) {
                throw ValidationException::withMessages([
                    'hiring_date' => 'La data di assunzione non può essere successiva all’inizio di un contratto esistente.',
                ]);
            }

            $this->syncUserAssignments($user, $request->validated());

            return $user;
        });

        // Ricarichiamo le relazioni aggiornate
        $result->load(['roles', 'assignmentRoles', 'contracts.workingDays']);

        return response()->json([
            'message' => 'Utente aggiornato con successo!',
            'data' => $result,
        ], 200);
    }

    /**
     * Disabilita l'utente (Soft Delete + Data di dimissione)
     */
    public function disable(Request $request, $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            $this->authorize('delete', $user);

            $validated = $request->validate([
                'resignation_date' => [
                    'required',
                    'date_format:Y-m-d',
                    'after_or_equal:'.$user->hiring_date->toDateString(),
                    'before_or_equal:'.Carbon::now('Europe/Rome')->toDateString(),
                ],
            ]);
            $date = $validated['resignation_date'];

            // Allocation writes lock this user too. Cancelling or shortening periods cannot introduce new role overlaps, so no project locks are needed here.
            $user->projectUsers()->whereDate('startwork_date', '>', $date)->delete();
            $user->projectUsers()->whereDate('startwork_date', '<=', $date)
                ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>', $date))
                ->update(['endwork_date' => Carbon::parse($date)->startOfDay()]);

            $user->update([
                'resignation_date' => $date,
            ]);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'Utente disabilitato con successo.',
        ], 200);
    }

    /**
     * Riabilita un utente precedentemente disabilitato (Ripristino da Soft Delete)
     */
    public function restore($id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        $this->authorize('restore', $user);

        DB::transaction(function () use ($user) {
            $user->restore();
            $user->update(['resignation_date' => null]);
        });

        return response()->json([
            'message' => 'Utente riabilitato con successo.',
            'data' => $user,
        ], 200);
    }

    private function saveUserContract(User $user, array $data): void
    {
        $contractData = array_intersect_key($data, array_flip([
            'contract_start_date', 'contract_end_date', 'weekly_hours', 'contract_type', 'has_rol',
        ]));
        if ($contractData === [] && ! array_key_exists('working_days', $data)) {
            return;
        }

        if (isset($data['contract_id'])) {
            $contract = $user->contracts()->whereKey($data['contract_id'])->lockForUpdate()->first();
            if ($contract === null) {
                throw ValidationException::withMessages(['contract_id' => 'Il contratto non appartiene all’utente o non è disponibile.']);
            }
        } else {
            $contracts = $user->contracts()->lockForUpdate()->limit(2)->get();
            if ($contracts->count() > 1) {
                throw ValidationException::withMessages(['contract_id' => 'Seleziona il contratto da modificare.']);
            }
            $contract = $contracts->first();
        }

        if ($contract === null && (! isset($contractData['contract_start_date']) || ! isset($contractData['weekly_hours']))) {
            throw ValidationException::withMessages(['contract_start_date' => 'Per creare un contratto servono data di inizio e ore settimanali.']);
        }

        $start = $contractData['contract_start_date'] ?? $contract?->contract_start_date;
        if (Carbon::parse($start)->startOfDay()->lt($user->hiring_date->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'contract_start_date' => 'Il contratto non può iniziare prima della data di assunzione.',
            ]);
        }
        $end = array_key_exists('contract_end_date', $contractData)
            ? $contractData['contract_end_date']
            : $contract?->contract_end_date;
        if ($end !== null && Carbon::parse($end)->startOfDay()->lt(Carbon::parse($start)->startOfDay())) {
            throw ValidationException::withMessages(['contract_end_date' => 'La fine del contratto non può precedere la data di inizio.']);
        }

        $schedule = null;
        if ($contract === null || array_key_exists('weekly_hours', $data) || array_key_exists('working_days', $data)) {
            $weeklyHours = (int) ($contractData['weekly_hours'] ?? $contract->weekly_hours);
            $days = $data['working_days'] ?? ($weeklyHours === 40
                ? WorkSchedule::fullTime()
                : ($contract?->workingDays()->get()->toArray() ?? []));
            $schedule = WorkSchedule::validate($days, $weeklyHours);
        }
        if ($contract === null) {
            $contract = $user->contracts()->create($contractData);
        } else {
            $contract->update($contractData);
        }

        if ($schedule !== null) {
            $contract->workingDays()->delete();
            foreach ($schedule as $day) {
                $contract->workingDays()->create([
                    'day_of_week' => $day['day_of_week'],
                    'start_time' => $day['start_time'] ?? null,
                    'end_time' => $day['end_time'] ?? null,
                    'is_working_day' => $day['is_working_day'] ?? false,
                ]);
            }
        }
    }

    private function syncUserAssignments(User $user, array $data): void
    {
        if (array_key_exists('role_id', $data)) {
            $user->roles()->sync([$data['role_id']]);
        }

        if (array_key_exists('assignment_role_id', $data)) {
            $assignments = $data['assignment_role_id'] === null
                ? []
                : [$data['assignment_role_id'] => ['expertise' => $data['expertise']]];
            $user->assignmentRoles()->sync($assignments);
        } elseif (array_key_exists('expertise', $data)) {
            $assignments = $user->assignmentRoles()->get();
            if ($assignments->count() !== 1) {
                throw ValidationException::withMessages([
                    'assignment_role_id' => 'Seleziona un unico incarico prima di modificarne la competenza.',
                ]);
            }
            $user->assignmentRoles()->updateExistingPivot($assignments->first()->id, [
                'expertise' => $data['expertise'],
            ]);
        }
    }
}
