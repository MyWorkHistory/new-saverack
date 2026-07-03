<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleOrderComment extends Model
{
    protected $fillable = [
        'wholesale_order_id',
        'user_id',
        'body',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime',
        'attachment_size',
    ];

    protected $casts = [
        'attachment_size' => 'integer',
    ];

    public function wholesaleOrder(): BelongsTo
    {
        return $this->belongsTo(WholesaleOrder::class, 'wholesale_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null && $this->attachment_path !== '';
    }
}
