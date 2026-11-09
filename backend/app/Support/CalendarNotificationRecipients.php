<?php

namespace App\Support;

use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CalendarNotificationRecipients
{
    public const LEADERS = ['Project Manager', 'Team Leader'];

    public static function forRequester(int $requesterId): Builder
    {
        $today = ProjectCalendar::today();
        $active = fn (Builder $query) => $query
            ->whereDate('startwork_date', '<=', $today)
            ->where(fn (Builder $dates) => $dates
                ->whereNull('endwork_date')->orWhereDate('endwork_date', '>=', $today));

        $projects = $active(ProjectUser::query())
            ->where('user_id', $requesterId)->where('allocation_type', 'project')->select('project_id');

        return User::query()->where(function (Builder $users) use ($active, $projects) {
            $users->whereHas('roles', fn (Builder $roles) => $roles->where('alias', 'Admin'));

            foreach (self::LEADERS as $role) {
                $users->orWhere(fn (Builder $leaders) => $leaders
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('alias', $role))
                    ->whereHas('projectUsers', fn (Builder $assignments) => $active($assignments)
                        ->whereIn('project_id', $projects)
                        ->whereHas('project', fn (Builder $project) => $project->where('is_course', false)->where('is_timesheet', false))
                        ->whereHas('assignmentRole', fn (Builder $assignmentRole) => $assignmentRole->where('alias', $role))));
            }
        });
    }
}
