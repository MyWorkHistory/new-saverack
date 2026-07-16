<?php

namespace App\Policies;

use App\Models\ResourceCalendarEvent;
use App\Models\User;

class ResourceCalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner()
            || $user->hasPermission('resources_calendar.view')
            || $user->hasPermission('resources_events.view');
    }

    public function view(User $user, ResourceCalendarEvent $event): bool
    {
        if (
            ! $user->isCrmOwner()
            && ! $user->hasPermission('resources_calendar.view')
            && ! $user->hasPermission('resources_events.view')
        ) {
            return false;
        }

        if ($event->is_personal) {
            return (int) $event->created_by_user_id === (int) $user->id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner()
            || $user->hasPermission('resources_calendar.create')
            || $user->hasPermission('resources_events.create');
    }

  public function update(User $user, ResourceCalendarEvent $event): bool
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        return (int) $event->created_by_user_id === (int) $user->id;
    }

    public function delete(User $user, ResourceCalendarEvent $event): bool
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return true;
        }

        return (int) $event->created_by_user_id === (int) $user->id;
    }
}
