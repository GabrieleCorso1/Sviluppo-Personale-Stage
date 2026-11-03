<?php

namespace App\Http\Controllers;

use App\AllocationOverview;
use App\Http\Requests\AllocationRequest;
use App\Models\AssignmentRole;
use App\Models\Necessity;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Support\ProjectCalendar;
use App\Support\ProjectFiles;
use App\Support\ProjectParticipantAvatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AllocationController extends Controller
{
    public function __construct(private AllocationOverview $overview) {}

    public function options(AllocationRequest $request): JsonResponse
    {
        return response()->json(['data' => [
            'types' => AllocationOverview::TYPES,
            'summary_type' => 'project',
            'statuses' => AllocationOverview::STATUSES,
            'roles' => AssignmentRole::whereIn('alias', array_keys(AllocationOverview::ROLES))->get()
                ->map(fn ($role) => $this->overview->role($role)),
        ]]);
    }

    public function summary(AllocationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->overview->summary()]);
    }

    public function index(AllocationRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $type = $filters['type'] ?? 'project';
        $projectsQuery = $this->overview->projects($type);
        if (isset($filters['status'])) {
            $projectsQuery->where('status', $filters['status']);
        }
        if (isset($filters['selected_project_id'])) {
            abort_unless((clone $projectsQuery)->whereKey($filters['selected_project_id'])->exists(), 404);
        }
        $projects = $projectsQuery->with('filestorage')->orderBy('alias')->orderBy('id')->get();
        $allAssignments = $this->overview->assignments($type);
        $usersQuery = $this->overview->resources();
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $usersQuery->where(fn ($query) => $query->where('first_name', 'like', '%'.$word.'%')
                ->orWhere('last_name', 'like', '%'.$word.'%'));
        }
        if (isset($filters['assignment_role_id']) || isset($filters['status'])) {
            $matching = (clone $allAssignments)->whereIn('project_id', $projects->pluck('id'));
            if (isset($filters['assignment_role_id'])) {
                $matching->where('assignmentrole_id', $filters['assignment_role_id']);
            }
            $usersQuery->whereIn('id', $matching->select('user_id'));
        }
        if (isset($filters['selected_project_id'])) {
            $usersQuery->withExists(['projectUsers as is_selected_member' => fn ($query) => $query
                ->whereIn('id', (clone $allAssignments)->where('project_id', $filters['selected_project_id'])->select('id'))])
                ->orderByDesc('is_selected_member');
        }
        $users = $usersQuery->with('filestorage')->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 50)->withQueryString();
        $rows = (clone $allAssignments)->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->with(['project', 'assignmentRole'])->orderBy('id')->get()->groupBy('user_id');
        $users->setCollection($users->getCollection()->map(function ($user) use ($rows, $projects, $type) {
            $assignments = $rows->get($user->id, collect());

            return array_merge($this->overview->resource($user), $this->overview->indicators($assignments, $type), [
                'allocations' => $assignments->whereIn('project_id', $projects->pluck('id'))
                    ->map(fn ($row) => $this->overview->assignment($row))->values(),
            ]);
        }));
        $counts = (clone $allAssignments)->whereIn('project_id', $projects->pluck('id'))->with('project')->get()->groupBy('project_id');
        $needs = $this->overview->necessityQuery()->whereIn('project_id', $projects->pluck('id'))->get()->groupBy('project_id');

        return response()->json(['data' => [
            'type' => $type, 'as_of' => ProjectCalendar::today(), 'selected_project_id' => $filters['selected_project_id'] ?? null,
            'projects' => $projects->map(fn ($project) => array_merge($this->overview->project($project), [
                'resources_count' => $counts->get($project->id, collect())->pluck('user_id')->unique()->count(),
                'has_difficulty' => $counts->get($project->id, collect())->contains(fn ($row) => $row->is_difficult),
                'necessities_count' => $needs->get($project->id, collect())->count(),
            ])),
            'resources' => $users,
        ]]);
    }

    public function project(AllocationRequest $request, Project $project): JsonResponse
    {
        $type = $request->validated('type', 'project');
        abort_unless($this->overview->projects($type)->whereKey($project->id)->exists(), 404);
        $rows = $this->overview->assignments($type)->where('project_id', $project->id)
            ->with(['assignmentRole', 'user.filestorage'])->get()->groupBy('user_id');

        return response()->json(['data' => array_merge($this->overview->project($project->load('filestorage')), [
            'resources_count' => $rows->count(),
            'resources' => $rows->map(fn ($assignments) => array_merge($this->overview->resource($assignments->first()->user), [
                'allocations' => $assignments->map(fn ($row) => $this->overview->assignment($row))->values(),
            ]))->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'necessities' => $this->overview->necessities($project),
        ])]);
    }

    public function resource(AllocationRequest $request, User $user): JsonResponse
    {
        abort_unless($user->isAllocation_visible, 404);
        $type = $request->validated('type', 'project');
        $rows = $this->overview->assignments($type, false)->where('user_id', $user->id)
            ->with(['project.filestorage', 'assignmentRole'])->orderByDesc('startwork_date')->orderBy('id')->get();
        $current = $rows->filter(fn ($row) => $this->overview->assignment($row)['is_current']);

        return response()->json(['data' => array_merge(
            $this->overview->resource($user->load('filestorage')),
            $this->overview->indicators($current, $type),
            ['allocations' => $rows->map(fn ($row) => array_merge($this->overview->assignment($row), [
                'project' => $this->overview->project($row->project),
            ]))],
        )]);
    }

    public function store(AllocationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $assignment = DB::transaction(function () use ($data) {
            $project = $this->overview->projects($data['type'])->lockForUpdate()->find($data['project_id']);
            if (! $project) {
                throw ValidationException::withMessages(['project_id' => 'Il progetto non appartiene alla tipologia selezionata.']);
            }
            $this->overview->resources()->lockForUpdate()->findOrFail($data['user_id']);
            $attributes = [
                'project_id' => $project->id, 'user_id' => $data['user_id'], 'allocation_type' => $data['type'],
                'assignmentrole_id' => $data['type'] === 'project' ? $data['assignment_role_id'] : null,
                'startwork_date' => $data['start_date'], 'endwork_date' => null, 'work_allocation' => 0,
            ];
            $this->checkOverlap($attributes);

            return ProjectUser::create($attributes);
        });

        return response()->json(['data' => $this->overview->assignment($assignment->load('assignmentRole'))], 201);
    }

    public function update(AllocationRequest $request, ProjectUser $allocation): JsonResponse
    {
        $data = $request->validated();
        $this->mutate($allocation, function ($row) use ($data) {
            if (isset($data['assignment_role_id'])) {
                if ($row->allocation_type !== 'project') {
                    throw ValidationException::withMessages(['assignment_role_id' => 'Timesheet e Corsi non prevedono un ruolo.']);
                }
                $row->assignmentrole_id = $data['assignment_role_id'];
            }
            if (isset($data['start_date'])) {
                $row->startwork_date = $data['start_date'];
            }
            if ($row->endwork_date && $row->startwork_date->gt($row->endwork_date)) {
                throw ValidationException::withMessages(['start_date' => 'La data di inizio non può essere successiva alla fine.']);
            }
            $this->checkOverlap($row->getAttributes(), $row->id);
            $row->save();
        });

        return response()->json(['data' => $this->overview->assignment($allocation->refresh()->load('assignmentRole'))]);
    }

    public function difficulty(AllocationRequest $request, ProjectUser $allocation): JsonResponse
    {
        $this->mutate($allocation, function ($row) use ($request) {
            if ($row->allocation_type !== 'project') {
                throw ValidationException::withMessages(['is_difficult' => 'La difficoltà è prevista solo per i ruoli nei Progetti.']);
            }
            $row->update(['is_difficult' => $request->validated('is_difficult')]);
        });

        return response()->json(['data' => $this->overview->assignment($allocation->refresh()->load('assignmentRole'))]);
    }

    public function conclude(AllocationRequest $request, ProjectUser $allocation): JsonResponse
    {
        $end = $request->validated('end_date');
        $this->mutate($allocation, function ($row) use ($end) {
            if ($end < $row->startwork_date->toDateString()) {
                throw ValidationException::withMessages(['end_date' => 'La data di fine non può precedere la data di inizio.']);
            }
            $attributes = array_merge($row->getAttributes(), ['endwork_date' => $end]);
            $this->checkOverlap($attributes, $row->id);
            $row->update(['endwork_date' => $end]);
        });

        return response()->json(['data' => $this->overview->assignment($allocation->refresh()->load('assignmentRole'))]);
    }

    public function destroy(AllocationRequest $request, ProjectUser $allocation): JsonResponse
    {
        $this->mutate($allocation, fn ($row) => $row->delete());

        return response()->json(['message' => 'Assegnazione eliminata.']);
    }

    public function saveNecessities(AllocationRequest $request, Project $project): JsonResponse
    {
        $roles = $request->validated('assignment_role_ids');
        DB::transaction(function () use ($project, $roles) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $query = $this->overview->necessityQuery()->where('project_id', $project->id);
            (clone $query)->whereNotIn('assignmentrole_id', $roles)->delete();
            foreach ($roles as $role) {
                Necessity::firstOrCreate(['project_id' => $project->id, 'assignmentrole_id' => $role]);
            }
            $project->touch();
        });

        return response()->json(['data' => $this->overview->necessities($project)]);
    }

    public function logo(AllocationRequest $request, Project $project): BinaryFileResponse
    {
        $path = ProjectFiles::logoPath($project->filestorage);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-project.svg'), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function avatar(AllocationRequest $request, User $user): BinaryFileResponse
    {
        abort_unless($user->isAllocation_visible, 404);
        $path = ProjectParticipantAvatar::privatePath($user);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-avatar.svg'), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function mutate(ProjectUser $allocation, callable $callback): void
    {
        DB::transaction(function () use ($allocation, $callback) {
            $this->overview->projects($allocation->allocation_type)->whereKey($allocation->project_id)->lockForUpdate()->firstOrFail();
            $this->overview->resources()->whereKey($allocation->user_id)->lockForUpdate()->firstOrFail();
            $callback($this->overview->assignments($allocation->allocation_type, false)->whereKey($allocation->id)->lockForUpdate()->firstOrFail());
        });
    }

    private function checkOverlap(array $attributes, ?int $ignore = null): void
    {
        $start = Carbon::parse($attributes['startwork_date'])->toDateString();
        $end = isset($attributes['endwork_date']) ? Carbon::parse($attributes['endwork_date'])->toDateString() : null;
        $query = ProjectUser::where('project_id', $attributes['project_id'])->where('user_id', $attributes['user_id'])
            ->where('allocation_type', $attributes['allocation_type'])
            ->when($attributes['allocation_type'] === 'project', fn ($query) => $query->where('assignmentrole_id', $attributes['assignmentrole_id']))
            ->when($ignore, fn ($query) => $query->where('id', '!=', $ignore))
            ->where(fn ($query) => $query->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $start));
        if ($end !== null) {
            $query->whereDate('startwork_date', '<=', $end);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['start_date' => 'Esiste già una assegnazione sovrapposta per questa risorsa, tipologia e ruolo.']);
        }
    }
}
