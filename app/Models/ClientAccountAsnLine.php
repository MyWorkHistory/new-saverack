<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountAsnLine extends Model
{
    public const LINE_STATUS_PENDING = 'pending';

    public const LINE_STATUS_PARTIAL = 'partial';

    public const LINE_STATUS_COMPLETED = 'completed';

    public const LINE_STATUSES = [
        self::LINE_STATUS_PENDING,
        self::LINE_STATUS_PARTIAL,
        self::LINE_STATUS_COMPLETED,
    ];

    protected $fillable = [
        'client_account_asn_id',
        'shiphero_product_id',
        'shiphero_legacy_id',
        'sku',
        'name',
        'image_url',
        'expected_qty',
        'accepted_qty',
        'rejected_qty',
        'line_status',
        'barcode',
        'weight',
        'length',
        'width',
        'height',
        'specs_cached_at',
        'sort_order',
    ];

    protected $casts = [
        'expected_qty' => 'integer',
        'accepted_qty' => 'integer',
        'rejected_qty' => 'integer',
        'sort_order' => 'integer',
        'weight' => 'decimal:4',
        'length' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'shiphero_legacy_id' => 'integer',
        'specs_cached_at' => 'datetime',
    ];

    public function asn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountAsn::class, 'client_account_asn_id');
    }
}
