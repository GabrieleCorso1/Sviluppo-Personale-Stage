<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->where('alias', 'Admin')
            ->exists() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isTimesheet = $this->boolean('is_timesheet');

        $rules = [
            'is_timesheet' => ['required', 'boolean'],
            'is_course' => ['missing'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
            'status' => [
                'sometimes',
                'nullable',
                Rule::in(['in corso', 'manutenzione', 'sospeso', 'concluso']),
            ],
            'priority' => [
                'sometimes',
                'nullable',
                Rule::in(['bassa', 'media', 'alta']),
            ],
        ];

        foreach (['project_manager_id', 'team_leader_id', 'scrum_master_id'] as $field) {
            $rules[$field] = $isTimesheet
                ? ['missing']
                : ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')];
        }

        if ($isTimesheet) {
            $rules['status'] = ['missing'];
            $rules['priority'] = ['missing'];
        }

        return $rules;
    }
}
