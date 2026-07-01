<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDraft extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'client_account_id',
        'order_number',
        'status',
        'shipping_address',
        'line_items',
        'shipping_carrier',
        'shipping_method',
        'packing_note',
        'gift_note',
        'tags',
        'allow_partial',
        'require_signature',
        'shiphero_order_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'line_items' => 'array',
        'tags' => 'array',
        'allow_partial' => 'boolean',
        'require_signature' => 'boolean',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
