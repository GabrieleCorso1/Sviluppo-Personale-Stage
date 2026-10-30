<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\IndexProjectRequest;
use App\Http\Requests\IndexProjectUserRequest;
use App\Http\Requests\ShowProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectNoteRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UploadProjectDeliveryPlanRequest;
use App\Http\Requests\UploadProjectLogoRequest;
use App\Http\Resources\ProjectResource;
use App\Models\AssignmentRole;
use App\Models\FileStorage;
use App\Models\Project;
use App\Support\ProjectCalendar;
use App\Support\ProjectFiles;
use App\Support\ProjectParticipantAvatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(IndexProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        $projects = Project::query()
            ->where('is_course', false)
            ->withMin('upcomingReviewSprints as next_sprint_review_date', 'end_date')
            ->with([
                'filestorage',
                'sprintsRequiringCsv:id,project_id,csv_path,is_concluded,is_billing',
                'currentProjectManagerAssignments.user:id,first_name,last_name',
                'currentTeamLeaderAssignment.user:id,first_name,last_name',
                'currentScrumMasterAssignment.user:id,first_name,last_name',
            ])
            ->where('is_timesheet', $request->boolean('is_timesheet'))
            ->when($request->filled('search'), function ($query) use ($data) {
                $query->where('alias', 'like', '%'.$data['search'].'%');
            })
            ->when($request->filled('status'), function ($query) use ($data) {
                $query->where('status', $data['status']);
            })
            ->when($request->filled('priority'), function ($query) use ($data) {
                $query->where('priority', $data['priority']);
            })
            ->when($request->filled('project_manager_id'), function ($query) use ($data) {
                $query->whereHas('currentProjectManagerAssignments', function ($assignment) use ($data) {
                    $assignment->where('user_id', $data['project_manager_id']);
                });
            })
            ->when($request->filled('team_leader_id'), function ($query) use ($data) {
                $query->whereHas('currentTeamLeaderAssignment', function ($assignment) use ($data) {
                    $assignment->where('user_id', $data['team_leader_id']);
                });
            })
            ->when($request->filled('scrum_master_id'), function ($query) use ($data) {
                $query->whereHas('currentScrumMasterAssignment', function ($assignment) use ($data) {
                    $assignment->where('user_id', $data['scrum_master_id']);
                });
            })
            ->orderBy('alias', $data['sort_direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        $projects->through(fn ($project) => (new ProjectResource($project))->resolve($request));

        $counts = [
            'projects' => Project::where('is_course', false)->where('is_timesheet', false)->count(),
            'timesheets' => Project::where('is_course', false)->where('is_timesheet', true)->count(),
        ];

        return response()->json(array_merge($projects->toArray(), [
            'counts' => $counts,
        ]));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['is_daily'] = $request->boolean('is_daily');
        $data['is_azure'] = $request->boolean('is_azure');
        $data['is_ux_ui'] = $request->boolean('is_ux_ui');
        $data['is_course'] = false;

        $project = DB::transaction(function () use ($data) {
            $project = Project::create($data);
            if (! $project->is_timesheet) {
                $role = AssignmentRole::where('alias', 'Project Manager')->first();
                abort_unless($role, 422, 'Incarico Project Manager non configurato.');
                $project->projectUsers()->create([
                    'user_id' => $data['project_manager_id'],
                    'assignmentrole_id' => $role->id,
                    'startwork_date' => $data['start_date'],
                    'endwork_date' => null,
                    // Allocation rules are pending; do not add an assumed percentage.
                    'work_allocation' => 0,
                ]);
            }

            return $project;
        });

        return response()->json([
            'message' => 'Progetto creato con successo.',
            'data' => (new ProjectResource($project))->resolve($request),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(
        ShowProjectRequest $request,
        Project $project
    ): JsonResponse {
        $project->load([
            'filestorage',
            'currentProjectManagerAssignments.user:id,first_name,last_name',
            'currentTeamLeaderAssignment.user:id,first_name,last_name',
            'currentScrumMasterAssignment.user:id,first_name,last_name',
        ]);

        return response()->json([
            'data' => (new ProjectResource($project))->resolve($request),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        $project->refresh()->load([
            'filestorage',
            'currentProjectManagerAssignments.user:id,first_name,last_name',
            'currentTeamLeaderAssignment.user:id,first_name,last_name',
            'currentScrumMasterAssignment.user:id,first_name,last_name',
        ]);

        return response()->json([
            'message' => 'Progetto modificato con successo.',
            'data' => (new ProjectResource($project))->resolve($request),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function updateNote(UpdateProjectNoteRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        return response()->json([
            'message' => 'Nota aggiornata con successo.',
            'data' => [
                'id' => $project->id,
                'note' => $project->note,
            ],
        ]);
    }

    public function users(IndexProjectUserRequest $request, Project $project): JsonResponse
    {
        $data = $request->validated();

        $today = ProjectCalendar::today();

        $assignments = $project->projectUsers()
            ->whereDate('startwork_date', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('endwork_date')
                    ->orWhereDate('endwork_date', '>=', $today);
            })
            ->with(['user:id,first_name,last_name,filestorage_id', 'user.filestorage', 'assignmentRole:id,alias'])
            ->whereHas('user', function ($query) use ($request, $data) {
                if ($request->filled('search')) {
                    $search = '%'.$data['search'].'%';
                    $query->where(function ($query) use ($search) {
                        $query->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $users = $assignments->groupBy('user_id')->map(function ($userAssignments) use ($project) {
            $user = $userAssignments->first()->user;

            return [
                'id' => $user->id,
                ...ProjectParticipantAvatar::fields($user, $project),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'assignment_roles' => $userAssignments->pluck('assignmentRole')
                    ->filter()->unique('id')->values()->map(fn ($role) => [
                        'id' => $role->id,
                        'alias' => $role->alias,
                    ]),
            ];
        })->sortBy(fn ($user) => $user['last_name'].' '.$user['first_name'])
            ->values();

        return response()->json([
            'data' => $users,
            'total' => $users->count(),
        ]);
    }

    public function uploadLogo(UploadProjectLogoRequest $request, Project $project): JsonResponse
    {
        $file = $request->file('logo');
        $path = $file->store('projects/logos', 'local');
        abort_if($path === false, 500, 'Impossibile salvare il logo.');

        try {
            $storedFile = DB::transaction(function () use ($path, $project) {
                $storedFile = FileStorage::create([
                    'path' => dirname($path),
                    'name' => pathinfo($path, PATHINFO_FILENAME),
                    'extension' => pathinfo($path, PATHINFO_EXTENSION),
                    'type' => 'image',
                ]);

                $project->update(['filestorage_id' => $storedFile->id]);

                return $storedFile;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Logo aggiornato con successo.',
            'data' => [
                'id' => $project->id,
                'filestorage_id' => $storedFile->id,
                'logo_url' => route('projects.logo', $project),
                'logo_requires_auth' => true,
            ],
        ]);
    }

    public function logo(ShowProjectRequest $request, Project $project): BinaryFileResponse
    {
        $path = ProjectFiles::logoPath($project->filestorage);

        return response()->file($path ? Storage::disk('local')->path($path) : public_path('images/defaults/default-project.svg'), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function uploadDeliveryPlan(UploadProjectDeliveryPlanRequest $request, Project $project): JsonResponse
    {
        abort_if($project->is_timesheet, 422, 'Il delivery plan non e previsto per i timesheet.');
        $path = $request->file('delivery_plan')->store('projects/delivery-plans', 'local');
        abort_if($path === false, 500, 'Impossibile salvare il delivery plan.');

        try {
            $project->update(['delivery_plan' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Delivery plan aggiornato con successo.',
            'data' => (new ProjectResource($project))->resolve($request),
        ]);
    }

    public function deliveryPlan(ShowProjectRequest $request, Project $project): BinaryFileResponse
    {
        $path = $project->delivery_plan;
        abort_if($project->is_timesheet || ! ProjectFiles::exists($path, 'projects/delivery-plans'), 404, 'Delivery plan non disponibile.');

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="delivery-plan.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(DeleteProjectRequest $request, Project $project): JsonResponse
    {
        $project->delete();

        return response()->json([
            'message' => 'Progetto eliminato con successo.',
        ]);
    }
}
