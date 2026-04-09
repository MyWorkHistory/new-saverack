<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountComment extends Model
{
    protected $fillable = [
        'client_account_id',
        'user_id',
        'body',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAttachment(): bool
    {
        $p = $this->attachment_path;

        return $p !== null && $p !== '';
    }
}
