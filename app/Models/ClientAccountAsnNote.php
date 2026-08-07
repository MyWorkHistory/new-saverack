<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountAsnNote extends Model
{
    protected $table = 'client_account_asn_notes';

    protected $fillable = [
        'client_account_asn_id',
        'user_id',
        'body',
    ];

    public function asn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
