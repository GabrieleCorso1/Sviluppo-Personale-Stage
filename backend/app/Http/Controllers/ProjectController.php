<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\IndexProjectRequest;
use App\Http\Requests\IndexProjectUserRequest;
use App\Http\Requests\ShowProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectNoteRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UploadProjectLogoRequest;
use App\Models\FileStorage;
use App\Models\Project;
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
            ->with([
                'projectManager:id,first_name,last_name',
                'teamLeader:id,first_name,last_name',
                'scrumMaster:id,first_name,last_name',
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
            ->orderBy('alias', $data['sort_direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        $counts = [
            'projects' => Project::where('is_timesheet', false)->count(),
            'timesheets' => Project::where('is_timesheet', true)->count(),
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

        $project = Project::create($data);

        return response()->json([
            'message' => 'Progetto creato con successo.',
            'data' => $project,
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
            'projectManager:id,first_name,last_name',
            'teamLeader:id,first_name,last_name',
            'scrumMaster:id,first_name,last_name',
        ]);

        return response()->json([
            'data' => $project,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        $project->refresh()->load([
            'projectManager:id,first_name,last_name',
            'teamLeader:id,first_name,last_name',
            'scrumMaster:id,first_name,last_name',
        ]);

        return response()->json([
            'message' => 'Progetto modificato con successo.',
            'data' => $project,
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

        $assignments = $project->projectUsers()
            ->with(['user:id,first_name,last_name', 'assignmentRole:id,alias'])
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

        $users = $assignments->groupBy('user_id')->map(function ($userAssignments) {
            $user = $userAssignments->first()->user;

            return [
                'id' => $user->id,
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
            ],
        ]);
    }

    public function logo(ShowProjectRequest $request, Project $project): BinaryFileResponse
    {
        $storedFile = $project->filestorage;
        if (! $storedFile || $storedFile->name === 'default-project') {
            return response()->file(public_path('images/defaults/default-project.svg'));
        }

        $path = $storedFile->path.'/'.$storedFile->name.'.'.$storedFile->extension;
        abort_unless(Storage::disk('local')->exists($path), 404, 'Logo non disponibile.');

        return response()->file(Storage::disk('local')->path($path));
    }

    public function destroy(DeleteProjectRequest $request, Project $project): JsonResponse
    {
        $project->delete();

        return response()->json([
            'message' => 'Progetto eliminato con successo.',
        ]);
    }
}
