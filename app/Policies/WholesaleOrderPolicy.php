<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleOrder;
use Illuminate\Support\Facades\Gate;

class WholesaleOrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ((int) ($user->client_account_id ?? 0) > 0) {
            return false;
        }

        return Gate::forUser($user)->allows('orders.view');
    }

    public function view(User $user, WholesaleOrder $order): bool
    {
        if ((int) ($user->client_account_id ?? 0) > 0) {
            return false;
        }
        if (! Gate::forUser($user)->allows('orders.view')) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $order->clientAccount);
    }

    public function create(User $user): bool
    {
        if ((int) ($user->client_account_id ?? 0) > 0) {
            return false;
        }

        return Gate::forUser($user)->allows('orders.update');
    }

    public function update(User $user, WholesaleOrder $order): bool
    {
        if (! $this->view($user, $order)) {
            return false;
        }

        return Gate::forUser($user)->allows('orders.update');
    }

    public function delete(User $user, WholesaleOrder $order): bool
    {
        return $this->update($user, $order);
    }

    public function comment(User $user, WholesaleOrder $order): bool
    {
        return $this->view($user, $order);
    }
}
