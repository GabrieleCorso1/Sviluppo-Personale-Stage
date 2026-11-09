<?php

namespace App\Http\Requests;

use App\AllocationOverview;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllocationRequest extends FormRequest
{
    private const OPERATIONAL_ROLES = [
        'Sviluppatore Front-end',
        'Sviluppatore Back-end',
        'Sviluppatore Full-stack',
        'UX-UI Designer',
    ];

    private bool $isAdmin = false;

    public function authorize(): bool
    {
        $user = $this->user();
        $this->isAdmin = $user?->roles()->where('alias', 'Admin')->exists() ?? false;
        if ($this->isAdmin) {
            return true;
        }

        if ($this->route()->getActionMethod() !== 'store' || $this->input('type') !== 'project') {
            return false;
        }

        $projectId = filter_var($this->input('project_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $project = $projectId === false ? null : Project::query()->find($projectId);

        return $project !== null && ($user?->can('assignMembers', $project) ?? false);
    }

    public function rules(): array
    {
        $type = $this->input('type', 'project');
        $role = Rule::exists('assignmentroles', 'id')->whereNull('deleted_at')
            ->whereIn('alias', $this->isAdmin ? array_keys(AllocationOverview::ROLES) : self::OPERATIONAL_ROLES);

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
                'assignment_role_ids' => ['present', 'array', 'max:'.count(AllocationOverview::ROLES)],
                'assignment_role_ids.*' => ['required', 'integer', 'distinct', $role],
            ],
            default => [
                'type' => ['sometimes', Rule::in(AllocationOverview::TYPES)],
                'search' => ['nullable', 'string', 'max:255'],
                'assignment_role_id' => $type === 'project' ? ['sometimes', 'integer', $role] : ['prohibited'],
                'status' => $type === 'project' ? ['sometimes', Rule::in(AllocationOverview::STATUSES)] : ['prohibited'],
                'selected_project_id' => ['sometimes', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ],
        };
    }
}
