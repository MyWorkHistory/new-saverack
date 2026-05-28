<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->isAdministrator();
    }

    public function view(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->isAdministrator();
    }

    public function update(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->isAdministrator();
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isCrmOwner() || $user->isAdministrator();
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
