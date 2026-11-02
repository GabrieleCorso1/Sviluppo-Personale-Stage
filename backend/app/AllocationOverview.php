<?php

namespace App;

use App\Models\AssignmentRole;
use App\Models\Necessity;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AllocationOverview
{
    public const TYPES = ['project', 'timesheet', 'course'];

    public const STATUSES = ['in corso', 'manutenzione', 'sospeso', 'concluso'];

    public const WORKING_STATUSES = ['in corso', 'manutenzione'];

    public const ROLES = [
        'Sviluppatore Front-end' => 'F',
        'Sviluppatore Back-end' => 'B',
        'Sviluppatore Full-stack' => 'FB',
        'UX-UI Designer' => 'D',
        'Team Leader' => 'TL',
    ];

    public function projects(string $type): Builder
    {
        return Project::query()->when($type === 'project', fn ($query) => $query->where('is_course', false))
            ->when($type === 'timesheet', fn ($query) => $query->where('is_timesheet', true))
            ->when($type === 'course', fn ($query) => $query->where('is_course', true));
    }

    public function resources(): Builder
    {
        return User::query()->where('isAllocation_visible', true);
    }

    public function assignments(string $type, bool $current = true): Builder
    {
        $query = ProjectUser::query()->where('allocation_type', $type)
            ->whereIn('project_id', $this->projects($type)->select('id'))
            ->whereIn('user_id', $this->resources()->select('id'));

        if ($current) {
            $query->whereDate('startwork_date', '<=', today()->toDateString())
                ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', today()->toDateString()));
        }

        return $query;
    }

    public function role(AssignmentRole $role): array
    {
        return ['id' => $role->id, 'name' => $role->alias, 'code' => self::ROLES[$role->alias] ?? null];
    }

    public function resource(User $user): array
    {
        return [
            'id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'full_name' => trim($user->first_name.' '.$user->last_name),
            'avatar' => $user->filestorage?->only(['id', 'path', 'name', 'extension', 'type']),
        ];
    }

    public function project(Project $project): array
    {
        return [
            'id' => $project->id, 'name' => $project->alias, 'status' => $project->status,
            'is_timesheet' => $project->is_timesheet, 'is_course' => $project->is_course,
            'logo' => $project->filestorage?->only(['id', 'path', 'name', 'extension', 'type']),
        ];
    }

    public function assignment(ProjectUser $assignment): array
    {
        return [
            'id' => $assignment->id, 'type' => $assignment->allocation_type,
            'project_id' => $assignment->project_id, 'user_id' => $assignment->user_id,
            'role' => $assignment->assignmentRole ? $this->role($assignment->assignmentRole) : null,
            'start_date' => $assignment->startwork_date->toDateString(),
            'end_date' => $assignment->endwork_date?->toDateString(),
            'is_difficult' => $assignment->is_difficult,
            'is_current' => $assignment->startwork_date->lte(today())
                && ($assignment->endwork_date === null || $assignment->endwork_date->gte(today())),
        ];
    }

    public function indicators(Collection $assignments): array
    {
        $working = $assignments->filter(fn ($assignment) => in_array($assignment->project->status, self::WORKING_STATUSES, true));
        $count = $working->pluck('project_id')->unique()->count();

        return [
            'projects_count' => $assignments->pluck('project_id')->unique()->count(),
            'working_projects_count' => $count,
            'is_unallocated' => $count === 0,
            'is_overloaded' => $count >= 3,
            'has_difficulty' => $assignments->contains(fn ($assignment) => $assignment->is_difficult),
            'difficult_projects_count' => $assignments->where('is_difficult', true)->pluck('project_id')->unique()->count(),
        ];
    }

    public function necessities(Project $project): array
    {
        return Necessity::with('assignmentRole')->where('project_id', $project->id)->orderBy('id')->get()
            ->map(fn ($need) => ['id' => $need->id, 'role' => $need->assignmentRole ? $this->role($need->assignmentRole) : null])->all();
    }

    public function summary(string $type): array
    {
        $users = $this->resources()->get();
        $assignments = $this->assignments($type)->with('project')->get()->groupBy('user_id');
        $indicators = $users->map(fn ($user) => $this->indicators($assignments->get($user->id, collect())));
        $lastUpdated = collect([
            User::withTrashed()->max('updated_at'), Project::withTrashed()->max('updated_at'),
            ProjectUser::withTrashed()->max('updated_at'), Necessity::max('updated_at'),
        ])->filter()->max();

        return [
            'type' => $type, 'as_of' => today()->toDateString(), 'last_updated_at' => $lastUpdated,
            'total_resources' => $users->count(),
            'allocated_resources' => $indicators->where('is_unallocated', false)->count(),
            'unallocated_resources' => $indicators->where('is_unallocated', true)->count(),
            'overloaded_resources' => $indicators->where('is_overloaded', true)->count(),
            'resources_with_difficulty' => $indicators->where('has_difficulty', true)->count(),
            'open_necessities' => Necessity::whereIn('project_id', $this->projects($type)->select('id'))->count(),
        ];
    }
}
