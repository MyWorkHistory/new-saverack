<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsnBillHistory extends Model
{
    protected $fillable = [
        'asn_bill_id',
        'user_id',
        'actor_name',
        'event_type',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function asnBill(): BelongsTo
    {
        return $this->belongsTo(AsnBill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
