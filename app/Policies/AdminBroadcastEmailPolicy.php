<?php

namespace App\Policies;

use App\Models\AdminBroadcastEmail;
use App\Models\User;

class AdminBroadcastEmailPolicy
{
    private function canManage(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, AdminBroadcastEmail $adminBroadcastEmail): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, AdminBroadcastEmail $adminBroadcastEmail): bool
    {
        return $this->canManage($user);
    }
}
