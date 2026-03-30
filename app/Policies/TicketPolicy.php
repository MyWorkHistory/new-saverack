<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner();
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isCrmOwner();
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner();
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isCrmOwner();
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isCrmOwner();
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $user->isCrmOwner();
    }
}
