<?php

namespace App\Policies;

use App\Models\ClientAccountOnDemandProduct;
use App\Models\User;

class ClientAccountOnDemandProductPolicy
{
    private function canManageInventory(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageInventory($user) || $user->hasPermission('inventory_on_demand.view');
    }

    public function view(User $user, ClientAccountOnDemandProduct $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageInventory($user) || $user->hasPermission('inventory_on_demand.update');
    }

    public function update(User $user, ClientAccountOnDemandProduct $product): bool
    {
        return $this->canManageInventory($user) || $user->hasPermission('inventory_on_demand.update');
    }

    public function delete(User $user, ClientAccountOnDemandProduct $product): bool
    {
        return $this->canManageInventory($user) || $user->hasPermission('inventory_on_demand.update');
    }
}
