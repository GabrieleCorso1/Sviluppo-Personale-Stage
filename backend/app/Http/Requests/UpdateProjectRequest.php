<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
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
        $isTimesheet = $this->route('project')->is_timesheet;
        $requiredOrNullable = $isTimesheet ? 'nullable' : 'required';

        return [
            'is_timesheet' => ['missing'],

            'alias' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:250'],

            'project_manager_id' => [
                'sometimes',
                $requiredOrNullable,
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],

            'start_date' => [
                'sometimes',
                $requiredOrNullable,
                'date_format:Y-m-d',
            ],

            'status' => [
                'sometimes',
                $requiredOrNullable,
                Rule::in(['in corso', 'manutenzione', 'sospeso', 'concluso']),
            ],

            'priority' => [
                'sometimes',
                $requiredOrNullable,
                Rule::in(['bassa', 'media', 'alta']),
            ],

            'is_daily' => ['sometimes', 'boolean'],
            'is_azure' => ['sometimes', 'boolean'],
            'is_ux_ui' => ['sometimes', 'boolean'],

            'azure_project_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'azure_team_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'bug_count_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'pbi_without_feature_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'pbi_past_feature_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
        ];
    }
}
