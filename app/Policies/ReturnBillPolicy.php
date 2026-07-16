<?php

namespace App\Policies;

use App\Models\ReturnBill;
use App\Models\User;

class ReturnBillPolicy
{
    private function canManageBilling(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing_return_bills.view');
    }

    public function view(User $user, ReturnBill $returnBill): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReturnBill $returnBill): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing_return_bills.update');
    }

    public function delete(User $user, ReturnBill $returnBill): bool
    {
        if (! $returnBill->isOpen()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing_return_bills.delete');
    }
}
