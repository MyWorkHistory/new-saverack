<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippedDaySnapshotAccount extends Model
{
    protected $fillable = [
        'shipped_day_snapshot_id',
        'client_account_id',
        'account_name',
        'orders_count',
    ];

    protected $casts = [
        'orders_count' => 'integer',
        'client_account_id' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ShippedDaySnapshot::class, 'shipped_day_snapshot_id');
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
