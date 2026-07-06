<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderLine extends Model
{
    public const BARCODE_SHIP_AS_IS = 'ship_as_is';

    public const BARCODE_UPLOADED = 'uploaded';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SHIP_AS_IS = 'ship_as_is';

    public const STATUS_BARCODE_READY = 'barcode_ready';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SHIP_AS_IS,
        self::STATUS_BARCODE_READY,
    ];

    protected $fillable = [
        'wholesale_order_id',
        'sku',
        'name',
        'image_url',
        'quantity',
        'quantity_picked',
        'status',
        'barcode_mode',
        'barcode_path',
        'barcode_original_name',
        'barcode_mime',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_picked' => 'integer',
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

    public function syncStatusFromBarcodeMode(): void
    {
        if ($this->barcode_mode === self::BARCODE_UPLOADED) {
            $this->status = self::STATUS_BARCODE_READY;
        } elseif ($this->barcode_mode === self::BARCODE_SHIP_AS_IS) {
            $this->status = self::STATUS_SHIP_AS_IS;
        }
    }

    public function isFullyPicked(): bool
    {
        return (int) $this->quantity_picked >= (int) $this->quantity && (int) $this->quantity > 0;
    }
}
