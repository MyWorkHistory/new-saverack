<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PutAwaySnapshot extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    protected $fillable = [
        'client_account_id',
        'warehouse_id',
        'computed_at',
        'row_count',
        'status',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'computed_at' => 'datetime',
        'row_count' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(PutAwaySnapshotRow::class);
    }
}
