<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'assignment_role_id' => ['sometimes', 'integer', Rule::exists('assignmentroles', 'id')->whereNull('deleted_at')],
            'expertise' => ['sometimes', Rule::in(['junior', 'senior'])],
            'isAllocation_visible' => ['sometimes', 'boolean'],
            'is_intern' => ['sometimes', 'boolean'],
            'gender' => ['sometimes', Rule::in(['male', 'female'])],
            'sort_by' => ['sometimes', Rule::in(['name', 'hiring_date', 'resignation_date'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
