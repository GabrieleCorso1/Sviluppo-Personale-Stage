<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determina se l'utente è autorizzato a fare questa richiesta.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Ottieni le regole di validazione che si applicano alla richiesta.
     */
    public function rules(): array
    {
        // Usiamo i campi presenti nella tua tabella utenti
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'assignment_role_id' => ['present_with:expertise', 'nullable', 'required_with:expertise', 'integer', Rule::exists('assignmentroles', 'id')->whereNull('deleted_at')],
            'expertise' => ['present_with:assignment_role_id', 'nullable', 'required_with:assignment_role_id', Rule::in(['junior', 'senior'])],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'azure_email' => ['required', 'string', 'email', 'max:255'], // <-- Aggiunto
            'company_email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['prohibited'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_date' => ['required', 'date'],
            'hiring_date' => ['required', 'date'],
            'filestorage_id' => ['required', 'exists:filestorage,id'],
            'is_intern' => ['nullable', 'boolean'],                  // <-- Aggiunto
            'isAllocation_visible' => ['nullable', 'boolean'],      // <-- Aggiunto
            'remember_me' => ['nullable', 'boolean'],
            'contract_start_date' => ['required', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'weekly_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'contract_type' => ['nullable', 'string', 'max:255'],

            // --- NUOVI CAMPI PER LA DISTRIBUZIONE ORARIA (Figma) ---
            'working_days' => ['sometimes', 'required', 'array', 'max:7'],
            'working_days.*.day_of_week' => ['required', 'distinct', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'working_days.*.start_time' => ['nullable', 'date_format:H:i'],
            'working_days.*.end_time' => ['nullable', 'date_format:H:i'],
            'working_days.*.is_working_day' => ['required', 'boolean'], // <-- Aggiunto
        ];
    }
}
