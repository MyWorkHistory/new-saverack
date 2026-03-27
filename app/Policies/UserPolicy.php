<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasPermission('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole('admin') || $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasPermission('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole('admin') || $user->hasPermission('users.update');
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasRole('admin') || $user->hasPermission('users.delete');
    }
}
