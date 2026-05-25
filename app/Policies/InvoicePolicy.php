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

    private function isPortalUser(User $user): bool
    {
        return (int) ($user->client_account_id ?? 0) > 0;
    }

    private function ownsAccount(User $user, Invoice $invoice): bool
    {
        $accountId = (int) ($user->client_account_id ?? 0);

        return $accountId > 0 && $accountId === (int) $invoice->client_account_id;
    }

    private function canViewBilling(User $user): bool
    {
        return $this->canManageBilling($user) || $user->hasPermission('billing.view');
    }

    public function viewAny(User $user): bool
    {
        if ($this->isPortalUser($user)) {
            return true;
        }

        return $this->canViewBilling($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            if ($invoice->status === Invoice::STATUS_DRAFT || $invoice->status === 'pending') {
                return false;
            }

            return $this->ownsAccount($user, $invoice);
        }

        if ($this->ownsAccount($user, $invoice)) {
            return true;
        }

        return $this->canViewBilling($user);
    }

    public function create(User $user): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        if ($invoice->isVoid()) {
            return false;
        }

        if (! $invoice->isEditableDraft()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.delete');
    }

    public function send(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($invoice->isVoid()) {
            return false;
        }

        if ($invoice->status === Invoice::STATUS_DRAFT && (int) $invoice->balance_due_cents <= 0) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function addCharge(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        if ($invoice->isVoid()) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }

    public function updateStatus(User $user, Invoice $invoice): bool
    {
        if ($this->isPortalUser($user)) {
            return false;
        }

        return $this->canManageBilling($user) || $user->hasPermission('billing.update');
    }
}
