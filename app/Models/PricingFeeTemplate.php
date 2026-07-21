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

    public const CATEGORY_PACKAGING = 'packaging';

    public const CATEGORY_AMAZON = 'amazon';

    public const CATEGORY_POSTAGE = 'postage';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_FULFILLMENT,
        self::CATEGORY_RETURNS,
        self::CATEGORY_STORAGE,
        self::CATEGORY_RECEIVING,
        self::CATEGORY_CUSTOM_WORK,
        self::CATEGORY_WHOLESALE,
        self::CATEGORY_PACKAGING,
        self::CATEGORY_AMAZON,
        self::CATEGORY_POSTAGE,
    ];

    /**
     * Categories shown on client account / portal fee schedules.
     * Postage is settings-only markup and is never provisioned to accounts.
     *
     * @var list<string>
     */
    public const CLIENT_VISIBLE_CATEGORIES = [
        self::CATEGORY_FULFILLMENT,
        self::CATEGORY_RETURNS,
        self::CATEGORY_STORAGE,
        self::CATEGORY_RECEIVING,
        self::CATEGORY_CUSTOM_WORK,
        self::CATEGORY_WHOLESALE,
        self::CATEGORY_PACKAGING,
        self::CATEGORY_AMAZON,
    ];

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        self::CATEGORY_FULFILLMENT => 'Fulfillment',
        self::CATEGORY_RETURNS => 'Returns',
        self::CATEGORY_STORAGE => 'Storage',
        self::CATEGORY_RECEIVING => 'Receiving',
        self::CATEGORY_CUSTOM_WORK => 'Custom Work',
        self::CATEGORY_WHOLESALE => 'Wholesale',
        self::CATEGORY_PACKAGING => 'Packaging',
        self::CATEGORY_AMAZON => 'Amazon',
        self::CATEGORY_POSTAGE => 'Postage',
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

    public static function isClientVisibleCategory(string $category): bool
    {
        return in_array($category, self::CLIENT_VISIBLE_CATEGORIES, true);
    }

    public static function isMarkupPercentCategory(string $category): bool
    {
        return strcasecmp(trim($category), self::CATEGORY_POSTAGE) === 0;
    }

    public static function categoryToFeeGroup(string $category): string
    {
        if (in_array($category, self::CATEGORIES, true)) {
            return $category;
        }

        return self::CATEGORY_FULFILLMENT;
    }
}
