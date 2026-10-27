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
        'remember_me' => ['nullable', 'boolean'],
        'contract_start_date' => ['required', 'date'],
        'weekly_hours' => ['required', 'integer', 'min:1', 'max:168'],
        'contract_type' => ['nullable', 'string', 'max:255'],      
        
        // --- NUOVI CAMPI PER LA DISTRIBUZIONE ORARIA (Figma) ---
        'working_days' => ['nullable', 'array'],
        'working_days.*.day_of_week' => ['required', 'string', 'max:10'],
        'working_days.*.start_time' => ['nullable', 'date_format:H:i'],
        'working_days.*.end_time' => ['nullable', 'date_format:H:i'],
        'working_days.*.is_working_day' => ['boolean'],// <-- Aggiunto
    ];
    }
}