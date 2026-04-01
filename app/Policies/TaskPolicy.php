<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('webmaster.view');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('webmaster.view');
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('webmaster.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('webmaster.update');
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('webmaster.delete');
    }
}
