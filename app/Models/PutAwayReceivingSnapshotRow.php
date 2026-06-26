<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PutAwayReceivingSnapshotRow extends Model
{
    protected $fillable = [
        'put_away_receiving_snapshot_id',
        'client_account_id',
        'sku',
        'name',
        'barcode',
        'image_url',
        'receiving_qty',
        'pickable_qty',
        'non_pickable_qty',
        'on_hand',
        'backorder',
    ];

    protected $casts = [
        'put_away_receiving_snapshot_id' => 'integer',
        'client_account_id' => 'integer',
        'receiving_qty' => 'integer',
        'pickable_qty' => 'integer',
        'non_pickable_qty' => 'integer',
        'on_hand' => 'integer',
        'backorder' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PutAwayReceivingSnapshot::class, 'put_away_receiving_snapshot_id');
    }
}
