<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        $roles = $user->roles()->whereIn('alias', ['Admin', 'Team Leader'])->pluck('alias');

        return $roles->contains('Admin') || (
            ! $project->is_course
            && ! $project->is_timesheet
            && $roles->contains('Team Leader')
            && (int) $project->currentTeamLeaderAssignment()->value('user_id') === $user->id
        );
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function assignMembers(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }
}
