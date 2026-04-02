<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'label',
        'module',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withPivot('assigned_at');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user')->withTimestamps();
    }
}
