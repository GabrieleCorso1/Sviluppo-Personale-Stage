<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Inserisci la tua email.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'password.required' => 'Inserisci la password.',
            'remember.boolean' => 'Il valore di Ricordami non è valido.',
        ];
    }
}
