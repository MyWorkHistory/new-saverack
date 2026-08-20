<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleBill;

class WholesaleBillPolicy
{
    private function canManage(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user)
            || $user->hasPermission('billing_wholesale_bills.view')
            || $user->hasPermission('billing.view');
    }

    public function view(User $user, WholesaleBill $bill): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user)
            || $user->hasPermission('billing_wholesale_bills.create')
            || $user->hasPermission('billing.create');
    }

    public function update(User $user, WholesaleBill $bill): bool
    {
        return $bill->isOpen() && (
            $this->canManage($user)
            || $user->hasPermission('billing_wholesale_bills.update')
            || $user->hasPermission('billing.update')
        );
    }
}
