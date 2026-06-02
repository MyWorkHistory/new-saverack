<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryRestockSnapshot extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    protected $fillable = [
        'warehouse_id',
        'computed_at',
        'rows',
        'row_count',
        'status',
        'error_message',
        'duration_ms',
        'refresh_started_at',
        'progress_page',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
        'refresh_started_at' => 'datetime',
        'rows' => 'array',
        'row_count' => 'integer',
        'duration_ms' => 'integer',
        'progress_page' => 'integer',
    ];
}
