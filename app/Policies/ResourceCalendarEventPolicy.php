<?php

namespace App\Policies;

use App\Models\ResourceCalendarEvent;
use App\Models\User;

class ResourceCalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.view');
    }

    public function view(User $user, ResourceCalendarEvent $event): bool
    {
        if (! $user->isCrmOwner() && ! $user->hasPermission('resources.view')) {
            return false;
        }

        if ($event->is_personal) {
            return (int) $event->created_by_user_id === (int) $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.create');
    }

    public function update(User $user, ResourceCalendarEvent $event): bool
    {
        if ($event->is_personal) {
            return (int) $event->created_by_user_id === (int) $user->id;
        }

        return $user->isCrmOwner() || $user->hasPermission('resources.update');
    }

    public function delete(User $user, ResourceCalendarEvent $event): bool
    {
        if ($event->is_personal) {
            return (int) $event->created_by_user_id === (int) $user->id;
        }

        return $user->isCrmOwner() || $user->hasPermission('resources.delete');
    }
}
