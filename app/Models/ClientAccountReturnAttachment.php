<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClientAccountReturnAttachment extends Model
{
    protected $table = 'client_account_return_attachments';

    protected $fillable = [
        'client_account_return_id',
        'uploaded_by_user_id',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function returnRecord(): BelongsTo
    {
        return $this->belongsTo(ClientAccountReturn::class, 'client_account_return_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function publicUrl(): ?string
    {
        $path = trim((string) $this->path);
        if ($path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
