<?php

namespace App\Policies;

use App\Models\Supply;
use App\Models\User;

class SupplyPolicy
{
    private function canManageSettings(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        if ($this->canManageSettings($user)) {
            return true;
        }

        return $user->hasPermission('resources_supplies.view')
            || $user->hasPermission('resources.view');
    }

    public function view(User $user, Supply $supply): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageSettings($user);
    }

    public function update(User $user, Supply $supply): bool
    {
        return $this->canManageSettings($user);
    }

    public function delete(User $user, Supply $supply): bool
    {
        return $this->canManageSettings($user);
    }
}
