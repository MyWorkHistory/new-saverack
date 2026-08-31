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

    /** Shared staff catalog — any staff with supplies update/create, not per-user. */
    private function canManageCatalog(User $user): bool
    {
        if ($this->canManageSettings($user)) {
            return true;
        }

        return $user->hasPermission('resources_supplies.update')
            || $user->hasPermission('resources_supplies.create')
            || $user->hasPermission('resources_supplies.delete')
            || $user->hasPermission('resources.update')
            || $user->hasPermission('resources.create')
            || $user->hasPermission('resources.delete');
    }

    public function viewAny(User $user): bool
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        // Shared team catalog — all CRM staff can browse supplies.
        if ($user->isCrmStaffUser()) {
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
        return $this->canManageCatalog($user);
    }

    public function update(User $user, Supply $supply): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, Supply $supply): bool
    {
        return $this->canManageCatalog($user);
    }
}
