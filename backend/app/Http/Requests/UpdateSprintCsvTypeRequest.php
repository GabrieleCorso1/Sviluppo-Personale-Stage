<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSprintCsvTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()->where('alias', 'Admin')->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'is_billing' => ['required', 'boolean'],
            'is_pilot' => ['required', 'boolean'],
        ];
    }
}
