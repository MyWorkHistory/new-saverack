<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipHeroWebhookEvent extends Model
{
    protected $table = 'shiphero_webhook_events';

    protected $fillable = [
        'event_id',
        'event_type',
        'client_account_id',
        'shiphero_order_id',
        'payload',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'client_account_id' => 'integer',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }
}
