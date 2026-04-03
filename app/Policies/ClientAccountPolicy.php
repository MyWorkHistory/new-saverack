<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\User;

class ClientAccountPolicy
{
    private function canManageClients(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.view');
    }

    public function view(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.view');
    }

    public function create(User $user): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.create');
    }

    public function update(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.update');
    }

    public function delete(User $user, ClientAccount $clientAccount): bool
    {
        return $this->canManageClients($user) || $user->hasPermission('clients.delete');
    }
}
