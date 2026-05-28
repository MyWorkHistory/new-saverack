<?php

namespace App\Policies;

use App\Models\PricingFeeTemplate;
use App\Models\User;

class PricingFeeTemplatePolicy
{
    private function canManageSettings(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageSettings($user);
    }

    public function view(User $user, PricingFeeTemplate $pricingFeeTemplate): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageSettings($user);
    }

    public function update(User $user, PricingFeeTemplate $pricingFeeTemplate): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, PricingFeeTemplate $pricingFeeTemplate): bool
    {
        return $this->create($user);
    }
}
