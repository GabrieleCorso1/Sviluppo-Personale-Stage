<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreUserRequest;
use Illuminate\Support\Facades\DB;
use App\Models\Contract;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\Response;

class UserController extends Controller
{
    /**
     * Restituisce la lista di tutti gli utenti (es. per un pannello admin).
     */
   public function index(Request $request): JsonResponse
{
    // STEP 4: Carichiamo le relazioni nidificate 'contracts.workingDays'
    $query = User::with(['roles', 'assignmentRoles', 'contracts.workingDays']);

    // 1. FILTRO DI RICERCA (es. ?search=mario)
    if ($request->has('search')) {
        $search = $request->input('search');
        $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }

    // 2. FILTRI SPECIFICI OPZIONALI (es. ?is_intern=1 o ?gender=male)
    if ($request->has('is_intern')) {
        $query->where('is_intern', $request->input('is_intern'));
    }

    if ($request->has('gender')) {
        $query->where('gender', $request->input('gender'));
    }

    // 3. PAGINAZIONE (default 10 elementi per pagina, modificabile con ?per_page=15)
    $perPage = $request->input('per_page', 10);
    $users = $query->paginate($perPage);

    return response()->json([
        'success' => true,
        'data' => $users
    ], 200);
}

    /**
     * Mostra i dati dell'utente attualmente autenticato usando la facciata Auth.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = auth()->user(); 

        return response()->json([
            'success' => true,
            'data' => $user
        ], 200);
    }

    /**
     * Elimina un utente specifico tramite il suo ID.
     */
    public function destroy($id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Utente non trovato.'
            ], 404);
        }

       $this->authorize('delete', $user);

        $user->delete();

        return response()->json([
            'message' => 'Utente eliminato con successo.'
        ], 200);
    }

    public function store(StoreUserRequest $request): JsonResponse
{
    $result = DB::transaction(function () use ($request) {
        
        // 1. Creazione dell'utente
        $user = User::create([
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'email' => $request->validated('email'),
            'azure_email' => $request->validated('azure_email'),
            'company_email' => $request->validated('company_email'),
            'password' => bcrypt($request->validated('password')),
            'gender' => $request->validated('gender'),
            'birth_date' => $request->validated('birth_date'),
            'hiring_date' => $request->validated('hiring_date'),
            'filestorage_id' => $request->validated('filestorage_id'),
            'is_intern' => $request->validated('is_intern', false),
            'isAllocation_visible' => $request->validated('isAllocation_visible', false),
            'remember_me' => $request->validated('remember_me', false),
        ]);

        // 2. Creazione del contratto associato
        $contract = Contract::create([
            'user_id' => $user->id,
            'contract_start_date' => $request->validated('contract_start_date'),
            'weekly_hours' => $request->validated('weekly_hours'),
            'contract_type' => $request->validated('contract_type'),
        ]);

        // STEP 3 (store): Salvataggio dei giorni lavorativi/distribuzione oraria
        if ($request->has('working_days')) {
            foreach ($request->validated('working_days') as $dayData) {
                $contract->workingDays()->create([
                    'day_of_week' => $dayData['day_of_week'],
                    'start_time' => $dayData['start_time'] ?? null,
                    'end_time' => $dayData['end_time'] ?? null,
                    'is_working_day' => $dayData['is_working_day'] ?? false,
                ]);
            }
        }

        return $user;
    });

    // Ricarichiamo le relazioni includendo anche i giorni lavorativi nella risposta
    $result->load('contracts.workingDays');

    return response()->json([
        'message' => 'Utente e contratto creati con successo!',
        'data' => $result
    ], 201);
}

public function update(UpdateUserRequest $request, User $user): JsonResponse
{
    $result = DB::transaction(function () use ($request, $user) {
        
        // 1. Prepariamo i dati dell'utente da aggiornare
        $userData = $request->validated();

        if (!empty($userData['password'])) {
            $userData['password'] = bcrypt($userData['password']);
        } else {
            unset($userData['password']);
        }

        // Aggiorniamo l'utente
        $user->update($userData);

        // 2. Aggiorniamo o creiamo il contratto associato
        if ($request->has(['contract_start_date', 'weekly_hours'])) {
            $contract = $user->contracts()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'contract_start_date' => $request->validated('contract_start_date'),
                    'weekly_hours' => $request->validated('weekly_hours'),
                    'contract_type' => $request->validated('contract_type', null),
                ]
            );

            // STEP 3 (update): Aggiornamento dei giorni lavorativi
            if ($request->has('working_days')) {
                $contract->workingDays()->delete();

                foreach ($request->validated('working_days') as $dayData) {
                    $contract->workingDays()->create([
                        'day_of_week' => $dayData['day_of_week'],
                        'start_time' => $dayData['start_time'] ?? null,
                        'end_time' => $dayData['end_time'] ?? null,
                        'is_working_day' => $dayData['is_working_day'] ?? false,
                    ]);
                }
            }
        }

        return $user;
    });

    // Ricarichiamo le relazioni aggiornate
    $result->load(['roles', 'assignmentRoles', 'contracts.workingDays']);

    return response()->json([
        'message' => 'Utente aggiornato con successo!',
        'data' => $result
    ], 200);
}

/**
 * Disabilita l'utente (Soft Delete + Data di dimissione)
 */
public function disable(Request $request, $id): JsonResponse
{
    $user = User::findOrFail($id);
    
    $this->authorize('delete', $user);

    $request->validate([
        'resignation_date' => ['required', 'date']
    ]);

    $user->update([
        'resignation_date' => $request->input('resignation_date')
    ]);

    $user->delete();

    return response()->json([
        'message' => 'Utente disabilitato con successo.'
    ], 200);
}

/**
 * Riabilita un utente precedentemente disabilitato (Ripristino da Soft Delete)
 */
public function restore($id): JsonResponse
{
    $user = User::withTrashed()->findOrFail($id);

    $this->authorize('restore', $user);

    $user->restore();
    $user->update([
        'resignation_date' => null
    ]);

    return response()->json([
        'message' => 'Utente riabilitato con successo.',
        'data' => $user
    ], 200);
}

/**
 * Restituisce i dati dei contatori (ferie, permessi, rol) per un utente specifico.
 */
public function getLeaves($id): JsonResponse
{
    $user = User::with('leaveBalances')->findOrFail($id);

    return response()->json([
        'success' => true,
        'data' => $user->leaveBalances
    ], 200);
}

/**
 * Aggiorna o crea i record dei contatori di ferie, permessi e R.O.L.
 */
public function updateLeaves(Request $request, $id): JsonResponse
{
    $user = User::findOrFail($id);

    // Validazione dei dati in arrivo dalla modale Figma
    $validated = $request->validate([
        'leaves' => ['required', 'array'],
        'leaves.*.leave_type' => ['required', 'in:ferie,permesso,rol'],
        'leaves.*.reference_month' => ['required', 'date'],
        'leaves.*.remaining_hours' => ['required', 'numeric', 'min:0'],
        'leaves.*.accrued_hours' => ['required', 'numeric', 'min:0'],
        'leaves.*.monthly_accrual' => ['required', 'numeric', 'min:0'],
    ]);

    DB::transaction(function () use ($user, $validated) {
        foreach ($validated['leaves'] as $leaveData) {
            $user->leaveBalances()->updateOrCreate(
                [
                    'leave_type' => $leaveData['leave_type'],
                    'reference_month' => $leaveData['reference_month'],
                ],
                [
                    'remaining_hours' => $leaveData['remaining_hours'],
                    'accrued_hours' => $leaveData['accrued_hours'],
                    'monthly_accrual' => $leaveData['monthly_accrual'],
                ]
            );
        }
    });

    return response()->json([
        'message' => 'Contatori ferie e permessi aggiornati con successo!',
        'data' => $user->fresh('leaveBalances')
    ], 200);
}

}