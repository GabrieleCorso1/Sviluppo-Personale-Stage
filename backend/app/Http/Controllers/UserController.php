<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreUserRequest;

class UserController extends Controller
{
    /**
     * Restituisce la lista di tutti gli utenti (es. per un pannello admin).
     */
   public function index(Request $request): JsonResponse
{
    // Inizializziamo la query di base
    $query = User::query();

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
        // Puoi usare $request->user() oppure la classe Auth
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
        // Se i dati arrivano qui, sono GIA' stati validati automaticamente da Laravel!
        // Se c'è un errore (es. email mancante), Laravel blocca tutto e restituisce 
        // autonomamente un errore JSON 422 senza nemmeno eseguire questo codice.

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

        return response()->json([
            'message' => 'Utente creato con successo!',
            'data' => $user
        ], 201);
    }

}
