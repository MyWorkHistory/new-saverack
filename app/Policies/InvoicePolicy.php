<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    private function canManageBilling(User $user): bool
    {
        return $user->isAdministrator() || $user->isCrmOwner();
    }

    public function viewAny(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.delete');
    }

    public function send(User $user, Invoice $invoice): bool
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        if ($invoice->isVoid() || $invoice->status === Invoice::STATUS_DRAFT) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        if ($invoice->isVoid() || $invoice->status === Invoice::STATUS_DRAFT) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function addCharge(User $user, Invoice $invoice): bool
    {
        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }
}
