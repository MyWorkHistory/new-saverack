<?php

namespace App\Policies;

use App\Models\ResourcePhoto;
use App\Models\User;

class ResourcePhotoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.view');
    }

    public function view(User $user, ResourcePhoto $resourcePhoto): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.view');
    }

    public function create(User $user): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.create');
    }

    public function delete(User $user, ResourcePhoto $resourcePhoto): bool
    {
        return $user->isCrmOwner() || $user->hasPermission('resources.delete');
    }
}
