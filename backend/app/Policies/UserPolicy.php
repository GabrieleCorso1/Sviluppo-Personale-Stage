<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determina se l'utente autenticato può aggiornare l'utente target.
     */
    public function update(User $authenticatedUser, User $targetUser): bool
    {
        // L'utente può modificare solo il proprio profilo (o puoi aggiungere logiche per i ruoli admin)
        return $authenticatedUser->id === $targetUser->id;
    }

    /**
     * Determina se l'utente autenticato può eliminare l'utente target.
     */
    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        // Esempio: non puoi cancellare te stesso
        return $authenticatedUser->id !== $targetUser->id;
    }
}

