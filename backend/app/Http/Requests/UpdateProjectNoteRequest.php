<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('project')) ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }
}
