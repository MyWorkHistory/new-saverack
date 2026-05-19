<?php

namespace App\Policies;

use App\Models\CustomBill;
use App\Models\User;

class CustomBillPolicy
{
    private function canManageBilling(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.view');
    }

    public function view(User $user, CustomBill $customBill): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.create');
    }

    public function update(User $user, CustomBill $customBill): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function delete(User $user, CustomBill $customBill): bool
    {
        if (! $customBill->isOpen()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.delete');
    }
}
