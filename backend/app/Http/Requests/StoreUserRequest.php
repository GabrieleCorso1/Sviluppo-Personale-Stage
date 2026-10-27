<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Determina se l'utente è autorizzato a fare questa richiesta.
     */
    public function authorize(): bool
    {
        // Per ora mettiamo true (permettiamo la richiesta). 
        // Più avanti, al punto delle Policies, potremo affinare questo controllo.
        return true;
    }

    /**
     * Ottieni le regole di validazione che si applicano alla richiesta.
     */
    public function rules(): array
    {
        // Usiamo i campi presenti nella tua tabella utenti
       return [
        'first_name' => ['required', 'string', 'max:255'],
        'last_name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        'azure_email' => ['required', 'string', 'email', 'max:255'], // <-- Aggiunto
        'company_email' => ['required', 'string', 'email', 'max:255'],
        'password' => ['required', 'string', 'min:8'],
        'gender' => ['nullable', 'in:male,female'],
        'birth_date' => ['required', 'date'],
        'hiring_date' => ['required', 'date'],
        'filestorage_id' => ['required', 'exists:filestorage,id'],
        'is_intern' => ['nullable', 'boolean'],                  // <-- Aggiunto
        'isAllocation_visible' => ['nullable', 'boolean'],      // <-- Aggiunto
        'remember_me' => ['nullable', 'boolean'],                // <-- Aggiunto
    ];
    }
}