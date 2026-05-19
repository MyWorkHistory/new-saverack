<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomBillHistory extends Model
{
    protected $fillable = [
        'custom_bill_id',
        'user_id',
        'actor_name',
        'event_type',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function customBill(): BelongsTo
    {
        return $this->belongsTo(CustomBill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
