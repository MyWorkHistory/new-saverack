<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountReturnLine extends Model
{
    protected $fillable = [
        'client_account_return_id',
        'shiphero_line_item_id',
        'sku',
        'name',
        'image_url',
        'order_qty',
        'return_qty',
        'return_reason',
        'restock',
        'sort_order',
    ];

    protected $casts = [
        'order_qty' => 'integer',
        'return_qty' => 'integer',
        'restock' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function clientAccountReturn(): BelongsTo
    {
        return $this->belongsTo(ClientAccountReturn::class, 'client_account_return_id');
    }
}
