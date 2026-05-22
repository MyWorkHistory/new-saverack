<?php

namespace App\Policies;

use App\Models\ClientAccountReturn;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ClientAccountReturnPolicy
{
    private function ownsAccount(User $user, int $clientAccountId): bool
    {
        return (int) ($user->client_account_id ?? 0) === $clientAccountId && $clientAccountId > 0;
    }

    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('inventory.view');
    }

    public function view(User $user, ClientAccountReturn $return): bool
    {
        if (! Gate::forUser($user)->allows('inventory.view')) {
            return false;
        }
        if ($this->ownsAccount($user, (int) $return->client_account_id)) {
            return true;
        }

        return Gate::forUser($user)->allows('view', $return->clientAccount);
    }

    public function update(User $user, ClientAccountReturn $return): bool
    {
        return $this->view($user, $return);
    }

    public function delete(User $user, ClientAccountReturn $return): bool
    {
        return $this->view($user, $return);
    }
}
