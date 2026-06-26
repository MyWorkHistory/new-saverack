<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PutAwayReceivingSnapshot extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    protected $fillable = [
        'warehouse_id',
        'computed_at',
        'row_count',
        'status',
        'error_message',
        'duration_ms',
        'skipped_unresolved_account',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
        'row_count' => 'integer',
        'duration_ms' => 'integer',
        'skipped_unresolved_account' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(PutAwayReceivingSnapshotRow::class);
    }
}
