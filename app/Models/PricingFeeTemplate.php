<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingFeeTemplate extends Model
{
    public const CATEGORY_FULFILLMENT = 'fulfillment';

    public const CATEGORY_RETURNS = 'returns';

    public const CATEGORY_STORAGE = 'storage';

    public const CATEGORY_RECEIVING = 'receiving';

    public const CATEGORY_CUSTOM_WORK = 'custom_work';

    public const CATEGORY_WHOLESALE = 'wholesale';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_FULFILLMENT,
        self::CATEGORY_RETURNS,
        self::CATEGORY_STORAGE,
        self::CATEGORY_RECEIVING,
        self::CATEGORY_CUSTOM_WORK,
        self::CATEGORY_WHOLESALE,
    ];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        self::CATEGORY_FULFILLMENT => 'Fulfillment',
        self::CATEGORY_RETURNS => 'Returns',
        self::CATEGORY_STORAGE => 'Storage',
        self::CATEGORY_RECEIVING => 'Receiving',
        self::CATEGORY_CUSTOM_WORK => 'Custom Work',
        self::CATEGORY_WHOLESALE => 'Wholesale',
    ];

    protected $fillable = [
        'name',
        'description',
        'category',
        'amount',
        'icon_path',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function accountFees(): HasMany
    {
        return $this->hasMany(ClientAccountFee::class, 'pricing_template_id');
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? ucfirst(str_replace('_', ' ', $category));
    }

    public static function categoryToFeeGroup(string $category): string
    {
        if (in_array($category, self::CATEGORIES, true)) {
            return $category;
        }

        return self::CATEGORY_FULFILLMENT;
    }
}
