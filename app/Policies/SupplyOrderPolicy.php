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

        // Team order history — all CRM staff see every submitted order.
        if ($user->isCrmStaffUser()) {
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
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function update(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    /**
     * Anyone who can view the supplies page can add to / edit the shared draft.
     */
    public function editDraft(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Only admins can submit the shared draft.
     */
    public function submitDraft(User $user): bool
    {
        return $this->create($user);
    }
}
