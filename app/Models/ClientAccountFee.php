<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountFee extends Model
{
    public const GROUP_FULFILLMENT = 'fulfillment';

    public const GROUP_RETURNS = 'returns';

    public const GROUP_STORAGE = 'storage';

    public const GROUP_RECEIVING = 'receiving';

    public const GROUP_CUSTOM_WORK = 'custom_work';

    /** @var list<string> */
    public const GROUPS = [
        self::GROUP_FULFILLMENT,
        self::GROUP_RETURNS,
        self::GROUP_STORAGE,
        self::GROUP_RECEIVING,
        self::GROUP_CUSTOM_WORK,
    ];

    public const LINE_FIRST_PICK = 'first_pick';

    public const LINE_ADDITIONAL_PICKS = 'additional_picks';

    public const LINE_RETURNS_PROCESSING = 'processing';

    public const LINE_RETURNS_ADDITIONAL_ITEMS = 'additional_items';

    public const LINE_RETURNS_ASSEMBLY = 'assembly';

    public const LINE_RETURNS_REPACKAGING = 'repackaging';

    public const LINE_RETURNS_DISPOSAL = 'disposal';

    public const LINE_RETURNS_NON_COMPLIANT = 'non_compliant';

    protected $fillable = [
        'client_account_id',
        'pricing_template_id',
        'fee_group',
        'line_code',
        'label',
        'description',
        'icon_path',
        'amount',
        'cost',
        'currency',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'cost' => 'decimal:4',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function pricingTemplate(): BelongsTo
    {
        return $this->belongsTo(PricingFeeTemplate::class, 'pricing_template_id');
    }
}
