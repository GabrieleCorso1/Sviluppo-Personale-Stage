<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $authenticatedUser): bool
    {
        return $this->hasPermission($authenticatedUser, 'users.view');
    }

    public function create(User $authenticatedUser): bool
    {
        return $this->hasPermission($authenticatedUser, 'users.manage');
    }

    public function update(User $authenticatedUser, User $targetUser): bool
    {
        return $this->hasPermission($authenticatedUser, 'users.manage');
    }

    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        return $authenticatedUser->id !== $targetUser->id
            && $this->hasPermission($authenticatedUser, 'users.manage');
    }

    public function restore(User $authenticatedUser, User $targetUser): bool
    {
        return $this->hasPermission($authenticatedUser, 'users.manage');
    }

    public function manageLeaves(User $authenticatedUser, User $targetUser): bool
    {
        return $this->hasPermission($authenticatedUser, 'users.leaves.manage');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }
}
