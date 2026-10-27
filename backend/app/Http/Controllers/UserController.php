<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Restituisce la lista di tutti gli utenti (es. per un pannello admin).
     */
    public function index(): JsonResponse
    {
        $users = User::all();

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

        $user->delete();

        return response()->json([
            'message' => 'Utente eliminato con successo.'
        ], 200);
    }
}