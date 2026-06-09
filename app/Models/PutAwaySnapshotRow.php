<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PutAwaySnapshotRow extends Model
{
    protected $fillable = [
        'put_away_snapshot_id',
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
        'put_away_snapshot_id' => 'integer',
        'receiving_qty' => 'integer',
        'pickable_qty' => 'integer',
        'non_pickable_qty' => 'integer',
        'on_hand' => 'integer',
        'backorder' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(PutAwaySnapshot::class, 'put_away_snapshot_id');
    }
}
