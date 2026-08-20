<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleBillHistory extends Model
{
    protected $fillable = [
        'wholesale_bill_id',
        'user_id',
        'actor_name',
        'event_type',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function wholesaleBill(): BelongsTo
    {
        return $this->belongsTo(WholesaleBill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
