<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountFee extends Model
{
    public const GROUP_FULFILLMENT = 'fulfillment';

    public const GROUP_RETURNS = 'returns';

    public const GROUP_STORAGE = 'storage';

    /** @var list<string> */
    public const GROUPS = [
        self::GROUP_FULFILLMENT,
        self::GROUP_RETURNS,
        self::GROUP_STORAGE,
    ];

    public const LINE_FIRST_PICK = 'first_pick';

    public const LINE_ADDITIONAL_PICKS = 'additional_picks';

    public const LINE_RETURNS_PROCESSING = 'processing';

    public const LINE_RETURNS_ADDITIONAL_ITEMS = 'additional_items';

    protected $fillable = [
        'client_account_id',
        'fee_group',
        'line_code',
        'label',
        'amount',
        'currency',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
