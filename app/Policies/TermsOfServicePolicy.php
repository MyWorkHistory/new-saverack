<?php

namespace App\Policies;

use App\Models\TermsOfService;
use App\Models\User;

class TermsOfServicePolicy
{
    private function canManageSettings(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageSettings($user);
    }

    public function view(User $user, TermsOfService $termsOfService): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, TermsOfService $termsOfService): bool
    {
        return $this->canManageSettings($user);
    }
}
