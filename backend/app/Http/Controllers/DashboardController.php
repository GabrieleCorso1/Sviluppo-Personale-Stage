<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\Project;
use App\Models\Sbi;
use App\Models\User;
use App\Policies\SbiPolicy;
use App\Support\ProjectCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SbiPolicy $sbiPolicy): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('roles.permissions');
        $roles = $user->roles->pluck('alias');
        $permissions = $user->roles->flatMap(fn ($role) => $role->permissions->pluck('name'));
        $admin = $roles->contains('Admin');
        $hr = $roles->contains('HR Manager') && $permissions->contains('users.leaves.manage');
        $manager = $roles->intersect(['Project Manager', 'Team Leader'])->isNotEmpty();
        $today = ProjectCalendar::today();
        $active = fn (Builder $assignments) => $assignments
            ->whereDate('startwork_date', '<=', $today)
            ->where(fn (Builder $period) => $period->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $today));

        $projects = Project::query()->where('status', 'in corso')
            ->where('is_timesheet', false)->where('is_course', false)
            ->when(! $admin, fn (Builder $query) => $query->where(fn (Builder $visible) => $visible
                ->whereHas('projectUsers', fn (Builder $assignments) => $active($assignments)->where('user_id', $user->id))
                ->orWhereIn('id', $sbiPolicy->managedProjects($user)->select('id'))))
            ->with(['sprints' => fn ($query) => $query->where('is_concluded', false)
                ->whereDate('end_date', '>=', $today)->orderBy('start_date')->orderBy('id')])
            ->orderBy('alias')->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->alias,
                'sprints' => $project->sprints->map(fn ($sprint) => [
                    'number' => $sprint->number,
                    'start' => $sprint->start_date->toDateString(),
                    'end' => $sprint->end_date->toDateString(),
                ])->values(),
            ]);

        $ownCalendar = $user->calendarEntries()->with('typology');
        $todayEntries = Calendar::query()->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
            ->whereHas('user')
            ->when(! $admin && ! $hr, fn (Builder $query) => $query->where('user_id', $user->id))
            ->with('typology')->get();
        $counts = $todayEntries->groupBy(fn (Calendar $entry) => $entry->typology?->alias)
            ->map(fn ($entries) => $entries->pluck('user_id')->unique()->count());

        $requests = (clone $ownCalendar)->whereHas('typology', fn ($query) => $query->whereIn('alias', ['Ferie', 'Permesso']))
            ->orderByDesc('start_date')->orderByDesc('id')->limit(16)->get()->map($this->entry(...));
        $pending = ($admin || $hr) ? Calendar::query()->where('status', 'pending')
            ->whereHas('typology', fn ($query) => $query->whereIn('alias', ['Ferie', 'Permesso']))
            ->whereHas('user')->with(['user', 'typology'])->orderBy('start_date')->orderBy('id')->limit(12)->get()
            ->map(fn (Calendar $entry) => $this->entry($entry) + [
                'person' => $entry->user->first_name.' '.$entry->user->last_name,
            ]) : [];

        $leave = $user->leaveBalances()->orderByDesc('reference_date')->orderByDesc('reference_month')
            ->orderByDesc('id')->get()->unique('leave_type')->values()
            ->map(fn ($balance) => ['type' => $balance->leave_type, 'hours' => (float) $balance->remaining_hours]);

        return response()->json([
            'today' => $today,
            'mode' => $admin ? 'admin' : ($hr ? 'hr' : ($manager ? 'manager' : 'personal')),
            'counts' => (object) $counts->all(),
            'projects' => $projects,
            'events' => (clone $ownCalendar)->where('status', 'approved')->whereDate('end_date', '>=', $today)
                ->orderBy('start_date')->orderBy('id')->limit(6)->get()->map($this->entry(...)),
            'requests' => $requests,
            'pending' => $pending,
            'leave' => $leave,
            'sbi' => [
                'received_unread' => Sbi::query()->where('receiver_id', $user->id)
                    ->whereHas('latestRevision', fn ($query) => $query->whereNull('viewed_at'))->count(),
                'sent_month' => Sbi::query()->where('writer_id', $user->id)
                    ->where('created_at', '>=', now()->subMonth())->count(),
            ],
            'interns' => $hr ? User::query()->where('is_intern', true)->orderBy('last_name')
                ->limit(12)->get(['id', 'first_name', 'last_name']) : [],
        ]);
    }

    private function entry(Calendar $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->typology?->alias,
            'start' => $entry->start_date->toDateString(),
            'end' => $entry->end_date->toDateString(),
            'status' => $entry->status,
            'start_time' => $entry->start_time,
            'end_time' => $entry->end_time,
        ];
    }
}
