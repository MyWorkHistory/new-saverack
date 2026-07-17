<?php

namespace App\Support;

use App\Models\User;

class CrmCommentUserSerializer
{
    /**
     * @return array{id: int, name: string, email: string|null, avatar_url: string|null}|null
     */
    public static function fromUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $avatarUrl = null;
        if ($user->relationLoaded('profile') && $user->profile !== null) {
            $avatarUrl = $user->profile->avatar_url;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $avatarUrl,
        ];
    }
}
