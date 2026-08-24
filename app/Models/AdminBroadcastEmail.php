<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminBroadcastEmail extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'from_address',
        'from_name',
        'subject',
        'body_html',
        'qty_sent',
        'recipient_count',
        'status',
        'sent_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'qty_sent' => 'integer',
        'recipient_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
