<?php

namespace App\Policies;

use App\Models\Tutorial;
use App\Models\User;

class TutorialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.view');
    }

    public function view(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.view');
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.create');
    }

    public function update(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.update');
    }

    public function delete(User $user, Tutorial $tutorial): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.delete');
    }

    public function comment(User $user, Tutorial $tutorial): bool
    {
        return $this->view($user, $tutorial);
    }
}
