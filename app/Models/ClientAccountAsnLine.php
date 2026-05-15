<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountAsnLine extends Model
{
    protected $fillable = [
        'client_account_asn_id',
        'shiphero_product_id',
        'sku',
        'name',
        'image_url',
        'expected_qty',
        'accepted_qty',
        'rejected_qty',
        'sort_order',
    ];

    protected $casts = [
        'expected_qty' => 'integer',
        'accepted_qty' => 'integer',
        'rejected_qty' => 'integer',
        'sort_order' => 'integer',
    ];

    public function asn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
    }
}
