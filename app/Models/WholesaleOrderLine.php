<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderLine extends Model
{
    public const BARCODE_SHIP_AS_IS = 'ship_as_is';

    public const BARCODE_UPLOADED = 'uploaded';

    protected $fillable = [
        'wholesale_order_id',
        'sku',
        'name',
        'image_url',
        'quantity',
        'barcode_mode',
        'barcode_path',
        'barcode_original_name',
        'barcode_mime',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function wholesaleOrder(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrder::class, 'wholesale_order_id');
    }

    public function hasUploadedBarcode(): bool
    {
        return $this->barcode_mode === self::BARCODE_UPLOADED
            && $this->barcode_path !== null
            && $this->barcode_path !== '';
    }
}
