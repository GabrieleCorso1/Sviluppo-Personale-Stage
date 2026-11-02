<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->where('alias', 'Admin')
            ->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }
}
