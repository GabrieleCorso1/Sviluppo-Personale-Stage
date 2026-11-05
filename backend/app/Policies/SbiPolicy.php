<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Sbi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SbiPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function isAdmin(User $user): bool
    {
        return $user->roles()->where('alias', 'Admin')->exists();
    }

    public function managedProjects(User $user): Builder
    {
        $query = Project::query()->where('is_course', false)->where('is_timesheet', false);
        if ($this->isAdmin($user)) {
            return $query;
        }
        if (! $this->create($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($query) use ($user) {
            $query->whereHas('currentProjectManagerAssignments', fn ($assignment) => $assignment->where('user_id', $user->id))
                ->orWhereHas('currentTeamLeaderAssignment', fn ($assignment) => $assignment->where('user_id', $user->id));
        });
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Sbi::query();
        if ($this->isAdmin($user)) {
            return $query;
        }

        return $query->where(function ($query) use ($user) {
            $query->where('writer_id', $user->id)->orWhere('receiver_id', $user->id)
                ->orWhereIn('project_id', $this->managedProjects($user)->select('id'));
        });
    }

    public function view(User $user, Sbi $sbi): bool
    {
        return ! $sbi->trashed() && $this->visibleQuery($user)->whereKey($sbi->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->roles()->whereIn('alias', ['Admin', 'Project Manager', 'Team Leader'])->exists();
    }

    public function update(User $user, Sbi $sbi): bool
    {
        return ! $sbi->trashed() && $user->id === $sbi->writer_id;
    }

    public function delete(User $user, Sbi $sbi): bool
    {
        return $this->update($user, $sbi);
    }

    public function reply(User $user, Sbi $sbi): bool
    {
        return ! $sbi->trashed() && $user->id === $sbi->receiver_id;
    }

    public function restore(User $user, Sbi $sbi): bool
    {
        return false;
    }

    public function forceDelete(User $user, Sbi $sbi): bool
    {
        return false;
    }
}
