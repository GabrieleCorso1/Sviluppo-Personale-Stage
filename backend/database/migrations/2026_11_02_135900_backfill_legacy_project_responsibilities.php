<?php

use App\Models\ProjectUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $roles = DB::table('assignmentroles')->whereNull('deleted_at')->pluck('id', 'alias');
            $projects = DB::table('projects')->where('is_timesheet', false)->where('is_course', false)
                ->whereNotNull('start_date')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();

            foreach ($projects as $project) {
                foreach (['project_manager_id' => 'Project Manager', 'team_leader_id' => 'Team Leader', 'scrum_master_id' => 'Scrum Master'] as $column => $alias) {
                    $roleId = $roles[$alias] ?? null;
                    if (! $roleId || ! $project->{$column}) {
                        continue;
                    }
                    $hasHistory = DB::table('project_user')->where('project_id', $project->id)
                        ->where('assignmentrole_id', $roleId)->exists();
                    if ($hasHistory || ! DB::table('users')->where('id', $project->{$column})->whereNull('deleted_at')->exists()) {
                        continue;
                    }

                    ProjectUser::create([
                        'project_id' => $project->id, 'user_id' => $project->{$column},
                        'assignmentrole_id' => $roleId, 'allocation_type' => 'project',
                        'startwork_date' => $project->start_date, 'endwork_date' => $project->end_date,
                        'work_allocation' => 0,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Transferred responsibilities are user data and must not be removed by a rollback.
    }
};
