<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountOnDemandProduct extends Model
{
    public const CATEGORY_CAPSULES = 'Capsules';
    public const CATEGORY_GUMMIES = 'Gummies';
    public const CATEGORY_SKIN_CREAM = 'Skin Cream';
    public const CATEGORY_LIQUIDS = 'Liquids';

    public const CATEGORIES = [
        self::CATEGORY_CAPSULES,
        self::CATEGORY_GUMMIES,
        self::CATEGORY_SKIN_CREAM,
        self::CATEGORY_LIQUIDS,
    ];

    protected $fillable = [
        'client_account_id',
        'sku',
        'name',
        'category',
        'price_cents',
    ];

    protected $casts = [
        'price_cents' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
