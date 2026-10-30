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

        $rules = [
            'is_timesheet' => ['missing'],
            'is_course' => ['missing'],

            'alias' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('projects', 'alias')->where(fn ($query) => $query
                    ->where('is_course', false)->where('is_timesheet', $isTimesheet))
                    ->ignore($this->route('project')->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:250'],

            'project_manager_id' => ['missing'],

            'start_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['in corso', 'manutenzione', 'sospeso', 'concluso']),
            ],

            'priority' => [
                'sometimes',
                'required',
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

        if ($isTimesheet) {
            foreach ([
                'project_manager_id', 'team_leader_id', 'scrum_master_id',
                'start_date', 'end_date', 'status', 'priority',
                'is_daily', 'is_azure', 'is_ux_ui', 'is_course', 'delivery_plan',
            ] as $field) {
                $rules[$field] = ['missing'];
            }
        }

        return $rules;
    }
}
