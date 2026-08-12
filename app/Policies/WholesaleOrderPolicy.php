<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleOrder;
use Illuminate\Support\Facades\Gate;

class WholesaleOrderPolicy
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
        return Gate::forUser($user)->allows('orders.view');
    }

    public function view(User $user, WholesaleOrder $order): bool
    {
        if ($this->isPortalUser($user)) {
            if (! Gate::forUser($user)->allows('orders.view')) {
                return false;
            }

            return $this->ownsAccount($user, (int) $order->client_account_id);
        }

        if (! Gate::forUser($user)->allows('orders.view')) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $order->clientAccount);
    }

    public function create(User $user): bool
    {
        if ($this->isPortalUser($user)) {
            return Gate::forUser($user)->allows('orders.create');
        }

        return Gate::forUser($user)->allows('orders.update')
            || Gate::forUser($user)->allows('orders.create');
    }

    public function update(User $user, WholesaleOrder $order): bool
    {
        if (! $this->view($user, $order)) {
            return false;
        }

        if ($this->isPortalUser($user)) {
            return Gate::forUser($user)->allows('orders.create')
                || Gate::forUser($user)->allows('orders.update');
        }

        return Gate::forUser($user)->allows('orders.update');
    }

    public function delete(User $user, WholesaleOrder $order): bool
    {
        if ($this->isPortalUser($user)) {
            if ($order->status !== WholesaleOrder::STATUS_DRAFT) {
                return false;
            }

            return $this->update($user, $order);
        }

        return $this->update($user, $order);
    }

    public function comment(User $user, WholesaleOrder $order): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        return $this->view($user, $order);
    }
}
