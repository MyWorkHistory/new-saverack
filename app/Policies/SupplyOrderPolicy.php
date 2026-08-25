<?php

namespace App\Policies;

use App\Models\SupplyOrder;
use App\Models\User;

class SupplyOrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        return $user->hasPermission('resources_supplies.view')
            || $user->hasPermission('resources.view');
    }

    public function view(User $user, SupplyOrder $supplyOrder): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        return $user->hasPermission('resources_supplies.create')
            || $user->hasPermission('resources.create')
            || $user->hasPermission('resources_supplies.update')
            || $user->hasPermission('resources.update');
    }
}
