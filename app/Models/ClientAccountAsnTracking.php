<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountAsnTracking extends Model
{
    protected $fillable = [
        'client_account_asn_id',
        'carrier',
        'tracking_number',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function asn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
    }
}
