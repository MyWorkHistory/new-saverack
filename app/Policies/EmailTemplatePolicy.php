<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\User;

class EmailTemplatePolicy
{
    private function canManageSettings(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    private function canViewTemplates(User $user): bool
    {
        if ($this->canManageSettings($user)) {
            return true;
        }

        return $user->hasPermission('leads.view');
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewTemplates($user);
    }

    public function view(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageSettings($user);
    }

    public function update(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManageSettings($user);
    }

    public function delete(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManageSettings($user);
    }
}
