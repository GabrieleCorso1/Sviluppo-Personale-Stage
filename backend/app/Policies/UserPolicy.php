<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $authenticatedUser): bool
    {
        return $this->isAdministratorOrHr($authenticatedUser)
            && $this->hasPermission($authenticatedUser, 'users.view');
    }

    public function view(User $authenticatedUser, User $targetUser): bool
    {
        return $this->viewAny($authenticatedUser);
    }

    public function create(User $authenticatedUser): bool
    {
        return $this->isAdministratorOrHr($authenticatedUser)
            && $this->hasPermission($authenticatedUser, 'users.manage');
    }

    public function update(User $authenticatedUser, User $targetUser): bool
    {
        return $this->create($authenticatedUser)
            && ($this->isAdministrator($authenticatedUser) || ! $this->isAdministratorOrHr($targetUser));
    }

    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        return $authenticatedUser->id !== $targetUser->id
            && $this->update($authenticatedUser, $targetUser);
    }

    public function restore(User $authenticatedUser, User $targetUser): bool
    {
        return $this->update($authenticatedUser, $targetUser);
    }

    public function manageLeaves(User $authenticatedUser, User $targetUser): bool
    {
        return $this->isAdministratorOrHr($authenticatedUser)
            && $this->hasPermission($authenticatedUser, 'users.leaves.manage');
    }

    private function isAdministrator(User $user): bool
    {
        return $user->roles()->where('alias', 'Admin')->exists();
    }

    private function isAdministratorOrHr(User $user): bool
    {
        return $user->roles()->whereIn('alias', ['Admin', 'HR Manager'])->exists();
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }
}
