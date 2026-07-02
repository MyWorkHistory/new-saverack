<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ClientAccountAsnPolicy
{
    private function isPortalUser(User $user): bool
    {
        return (int) ($user->client_account_id ?? 0) > 0;
    }

    private function ownsAccount(User $user, int $clientAccountId): bool
    {
        return (int) ($user->client_account_id ?? 0) === $clientAccountId && $clientAccountId > 0;
    }

    public function viewAny(User $user): bool
    {
        if ($this->isPortalUser($user)) {
            return Gate::forUser($user)->allows('inventory.view');
        }

        return Gate::forUser($user)->allows('receiving.view');
    }

    public function view(User $user, ClientAccountAsn $asn): bool
    {
        if ($this->isPortalUser($user)) {
            if (! Gate::forUser($user)->allows('inventory.view')) {
                return false;
            }
            if ($this->ownsAccount($user, (int) $asn->client_account_id)) {
                return true;
            }

            return Gate::forUser($user)->allows('view', $asn->clientAccount);
        }

        if (! Gate::forUser($user)->allows('receiving.view')) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $asn->clientAccount);
    }

    public function create(User $user, ClientAccount $account): bool
    {
        if ($this->isPortalUser($user)) {
            if (! Gate::forUser($user)->allows('inventory.view')) {
                return false;
            }
            if ($this->ownsAccount($user, (int) $account->id)) {
                return true;
            }

            return Gate::forUser($user)->allows('view', $account);
        }

        if (! Gate::forUser($user)->allows('receiving.update')) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $account);
    }

    public function update(User $user, ClientAccountAsn $asn): bool
    {
        if ($this->isPortalUser($user)) {
            return $this->view($user, $asn);
        }

        if (! Gate::forUser($user)->allows('receiving.update')) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $asn->clientAccount);
    }

    public function delete(User $user, ClientAccountAsn $asn): bool
    {
        return $this->update($user, $asn);
    }
}
