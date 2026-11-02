<?php

namespace App\Http\Requests;

use App\AllocationOverview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()->where('alias', 'Admin')->exists() ?? false;
    }

    public function rules(): array
    {
        $role = Rule::exists('assignmentroles', 'id')->whereNull('deleted_at')
            ->whereIn('alias', array_keys(AllocationOverview::ROLES));

        return match ($this->route()->getActionMethod()) {
            'store' => [
                'type' => ['required', Rule::in(AllocationOverview::TYPES)],
                'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
                'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('isAllocation_visible', true)],
                'assignment_role_id' => ['required_if:type,project', 'nullable', 'integer', $role, 'prohibited_unless:type,project'],
                'start_date' => ['required', 'date_format:Y-m-d'],
            ],
            'update' => [
                'type' => ['prohibited'], 'project_id' => ['prohibited'], 'user_id' => ['prohibited'],
                'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'assignment_role_id' => ['sometimes', 'required', 'integer', $role],
            ],
            'difficulty' => ['is_difficult' => ['required', 'boolean']],
            'conclude' => ['end_date' => ['required', 'date_format:Y-m-d']],
            'saveNecessities' => [
                'assignment_role_ids' => ['present', 'array', 'max:5'],
                'assignment_role_ids.*' => ['required', 'integer', 'distinct', $role],
            ],
            default => [
                'type' => ['sometimes', Rule::in(AllocationOverview::TYPES)],
                'search' => ['nullable', 'string', 'max:255'],
                'assignment_role_id' => ['sometimes', 'integer', $role],
                'status' => ['sometimes', Rule::in(AllocationOverview::STATUSES)],
                'selected_project_id' => ['sometimes', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ],
        };
    }
}
