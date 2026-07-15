<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    private function isStaffAdmin(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('projects.update');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('projects.delete');
    }
}
