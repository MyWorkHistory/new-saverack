<?php

namespace App\Policies;

use App\Models\AsnBill;
use App\Models\User;

class AsnBillPolicy
{
    private function canManageBilling(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.view');
    }

    public function view(User $user, AsnBill $asnBill): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.create');
    }

    public function update(User $user, AsnBill $asnBill): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function delete(User $user, AsnBill $asnBill): bool
    {
        if (! $asnBill->isOpen()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.delete');
    }
}
