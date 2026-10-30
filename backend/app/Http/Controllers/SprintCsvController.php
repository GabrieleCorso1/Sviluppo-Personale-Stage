<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowProjectRequest;
use App\Http\Requests\UpdateSprintCsvTypeRequest;
use App\Http\Requests\UploadSprintCsvRequest;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SprintCsvController extends Controller
{
    public function index(ShowProjectRequest $request, Project $project): JsonResponse
    {
        abort_if($project->is_timesheet, 404);
        $groups = ['uploaded' => [], 'missing' => [], 'not_required' => [], 'pending' => []];
        foreach ($project->sprints()->orderByDesc('number')->orderBy('id')->get() as $sprint) {
            $data = $this->sprintData($project, $sprint);
            $groups[$data['csv_status']][] = $data;
        }

        return response()->json([
            'data' => $groups,
            'counts' => array_map('count', $groups),
        ]);
    }

    public function upload(UploadSprintCsvRequest $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->checkProject($project, $sprint);
        abort_if($sprint->is_billing, 422, 'Gli sprint di fatturazione non richiedono CSV.');
        $path = $request->file('csv')->store('sprints/csv', 'local');
        abort_if($path === false, 500, 'Impossibile salvare il CSV.');
        try {
            $sprint->update(['csv_path' => $path, 'has_csv' => true]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'CSV caricato con successo.',
            'data' => $this->sprintData($project, $sprint),
        ]);
    }

    public function download(ShowProjectRequest $request, Project $project, Sprint $sprint): BinaryFileResponse
    {
        $this->checkProject($project, $sprint);
        abort_unless($sprint->hasCsvFile(), 404);

        return response()->download(Storage::disk('local')->path($sprint->csv_path), 'sprint-'.$sprint->number.'.csv', [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function updateType(UpdateSprintCsvTypeRequest $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->checkProject($project, $sprint);
        abort_if($request->boolean('is_billing') && $request->boolean('is_pilot'), 422, 'Scegliere sprint pilota oppure fatturazione.');
        $sprint->update($request->validated());

        return response()->json([
            'message' => 'Tipo sprint aggiornato.',
            'data' => $this->sprintData($project, $sprint),
        ]);
    }

    private function checkProject(Project $project, Sprint $sprint): void
    {
        abort_if($project->is_timesheet || $sprint->project_id !== $project->id, 404);
    }

    private function sprintData(Project $project, Sprint $sprint): array
    {
        $hasFile = $sprint->hasCsvFile();
        $status = $sprint->csvStatus();

        return [
            'id' => $sprint->id,
            'number' => $sprint->number,
            'is_concluded' => $sprint->is_concluded,
            'is_pilot' => $sprint->is_pilot,
            'is_billing' => $sprint->is_billing,
            'has_csv' => $hasFile,
            'csv_status' => $status,
            'csv_url' => $hasFile ? route('projects.sprints.csv', [$project, $sprint]) : null,
            'csv_requires_auth' => $hasFile,
        ];
    }
}
