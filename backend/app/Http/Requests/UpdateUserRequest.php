<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        // Recuperiamo l'ID dell'utente dalla rotta (es. /api/users/{user})
        $userId = $this->route('user');

        return [
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'assignment_role_id' => ['present_with:expertise', 'nullable', 'required_with:expertise', 'integer', Rule::exists('assignmentroles', 'id')->whereNull('deleted_at')],
            'expertise' => ['present_with:assignment_role_id', 'nullable', 'required_with:assignment_role_id', Rule::in(['junior', 'senior'])],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($userId),
            ],
            'azure_email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'company_email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_date' => ['sometimes', 'required', 'date'],
            'hiring_date' => ['sometimes', 'required', 'date'],
            'resignation_date' => ['nullable', 'date'],
            'filestorage_id' => ['sometimes', 'required', 'exists:filestorage,id'],
            'is_intern' => ['sometimes', 'boolean'],
            'isAllocation_visible' => ['sometimes', 'boolean'],
            'remember_me' => ['sometimes', 'boolean'],

            // Campi Contratto
            'contract_id' => ['sometimes', 'required', 'integer', Rule::exists('contracts', 'id')->where('user_id', $this->route('user')->getKey())->whereNull('deleted_at')],
            'contract_end_date' => ['nullable', 'date'],
            'contract_start_date' => ['sometimes', 'required', 'date'],
            'weekly_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:168'],
            'contract_type' => ['nullable', 'string', 'max:255'],

            // --- NUOVI CAMPI PER LA DISTRIBUZIONE ORARIA (Figma) ---
            'working_days' => ['sometimes', 'required', 'array', 'max:7'],
            'working_days.*.day_of_week' => ['required', 'distinct', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'working_days.*.start_time' => ['nullable', 'date_format:H:i'],
            'working_days.*.end_time' => ['nullable', 'date_format:H:i'],
            'working_days.*.is_working_day' => ['required', 'boolean'],
        ];
    }
}
