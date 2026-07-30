<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippedDaySnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'total_count',
        'captured_at',
        'timezone',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_count' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(ShippedDaySnapshotAccount::class, 'shipped_day_snapshot_id');
    }
}
