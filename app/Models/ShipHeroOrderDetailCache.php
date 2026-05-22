<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipHeroOrderDetailCache extends Model
{
    protected $table = 'shiphero_order_detail_cache';

    protected $fillable = [
        'client_account_id',
        'order_id',
        'order_json',
        'synced_at',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'order_json' => 'array',
        'synced_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }
}
