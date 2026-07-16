<?php

namespace App\Policies;

use App\Models\Tutorial;
use App\Models\User;

class TutorialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources_tutorials.view');
    }

    public function view(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources_tutorials.view');
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources_tutorials.create');
    }

    public function update(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources_tutorials.update');
    }

    public function delete(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources_tutorials.delete');
    }

    public function comment(User $user, Tutorial $tutorial): bool
    {
        return $this->view($user, $tutorial);
    }
}
