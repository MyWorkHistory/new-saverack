<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    private function isStaffAdmin(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('leads.update');
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->isStaffAdmin($user) || $user->hasPermission('leads.delete');
    }
}
