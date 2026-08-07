<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountAsnAttachment extends Model
{
    protected $table = 'client_account_asn_attachments';

    protected $fillable = [
        'client_account_asn_id',
        'uploaded_by_user_id',
        'original_name',
        'path',
        'mime',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function asn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower(trim((string) $this->mime)), 'image/');
    }
}
