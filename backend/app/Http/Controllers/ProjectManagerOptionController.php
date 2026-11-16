<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProjectManagerOptionRequest;
use App\Models\User;
use App\Models\Project;
use App\Support\ProjectCalendar;
use Illuminate\Http\JsonResponse;

class ProjectManagerOptionController extends Controller
{
    public function index(IndexProjectManagerOptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = User::query()->select(['id', 'first_name', 'last_name']);
        if (! $request->user()->roles()->where('alias', 'Admin')->exists()) {
            $owned = Project::where('is_course', false)->where('is_timesheet', false)
                ->whereHas('currentProjectManagerAssignments', fn ($assignment) => $assignment->where('user_id', $request->user()->id));
            $query->where(fn ($users) => $users->whereKey($request->user()->id)
                ->orWhereHas('projectUsers', fn ($assignments) => $assignments
                    ->whereIn('project_id', $owned->select('id'))
                    ->whereDate('startwork_date', '<=', ProjectCalendar::today())
                    ->where(fn ($period) => $period->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', ProjectCalendar::today()))));
        }

        $users = $query
            ->when($request->filled('search'), function ($query) use ($data) {
                $terms = preg_split('/\s+/u', trim($data['search']), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $query->where(function ($query) use ($term) {
                        $query->where('first_name', 'like', '%'.$term.'%')
                            ->orWhere('last_name', 'like', '%'.$term.'%');
                    });
                }
            })
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($data['per_page'] ?? 20)->withQueryString();

        return response()->json($users);
    }
}
