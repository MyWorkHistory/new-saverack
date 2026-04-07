<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** Admins (any naming) + CRM owner always manage users; staff uses explicit permissions only. */
    private function canManageUsers(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageUsers($user) || $user->hasPermission('users.view');
    }

    public function view(User $user, User $model): bool
    {
        if ($model->client_account_id !== null) {
            return false;
        }

        return $this->canManageUsers($user) || $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $this->canManageUsers($user) || $user->hasPermission('users.create');
    }

    public function update(User $user, User $model): bool
    {
        if ($model->client_account_id !== null) {
            return false;
        }

        return $this->canManageUsers($user) || $user->hasPermission('users.update');
    }

    public function delete(User $user, User $model): bool
    {
        if ($model->client_account_id !== null) {
            return false;
        }

        if ($user->id === $model->id) {
            return false;
        }

        return $this->canManageUsers($user) || $user->hasPermission('users.delete');
    }
}
