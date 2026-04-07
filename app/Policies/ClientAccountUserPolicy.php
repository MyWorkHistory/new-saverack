<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\User;

class ClientAccountUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator()
            || $user->isCrmOwner()
            || $user->hasPermission('clients.view')
            || $user->hasPermission('client_users.view');
    }

    public function view(User $user, User $target, ClientAccount $account): bool
    {
        if ($target->client_account_id === null
            || (int) $target->client_account_id !== (int) $account->id) {
            return false;
        }

        return $this->viewAny($user);
    }

    public function create(User $user, ClientAccount $account): bool
    {
        if (! $user->isAdministrator() && ! $user->isCrmOwner() && ! $user->hasPermission('client_users.create')) {
            return false;
        }

        return $user->can('view', $account);
    }

    public function update(User $user, User $target, ClientAccount $account): bool
    {
        if ($target->client_account_id === null
            || (int) $target->client_account_id !== (int) $account->id) {
            return false;
        }

        return $user->isAdministrator()
            || $user->isCrmOwner()
            || $user->hasPermission('client_users.update');
    }

    public function delete(User $user, User $target, ClientAccount $account): bool
    {
        if ($target->client_account_id === null
            || (int) $target->client_account_id !== (int) $account->id) {
            return false;
        }

        if ($target->is_account_primary) {
            return false;
        }

        return $user->isAdministrator()
            || $user->isCrmOwner()
            || $user->hasPermission('client_users.delete');
    }
}
