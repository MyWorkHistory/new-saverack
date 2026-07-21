<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tutorial extends Model
{
    public const CATEGORY_ACCOUNTS = 'accounts';

    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_INVENTORY = 'inventory';

    public const CATEGORY_RECEIVING = 'receiving';

    public const CATEGORY_RETURNS = 'returns';

    public const CATEGORY_SETTINGS = 'settings';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORIES = [
        self::CATEGORY_ACCOUNTS,
        self::CATEGORY_ORDERS,
        self::CATEGORY_INVENTORY,
        self::CATEGORY_RECEIVING,
        self::CATEGORY_RETURNS,
        self::CATEGORY_SETTINGS,
        self::CATEGORY_BILLING,
    ];

    protected $fillable = [
        'title',
        'description',
        'category',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TutorialComment::class)->orderBy('created_at');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ResourcePhoto::class)->orderByDesc('sort_order')->orderByDesc('id');
    }

    public static function categoryLabel(?string $category): string
    {
        $map = [
            self::CATEGORY_ACCOUNTS => 'Accounts',
            self::CATEGORY_ORDERS => 'Orders',
            self::CATEGORY_INVENTORY => 'Inventory',
            self::CATEGORY_RECEIVING => 'Receiving',
            self::CATEGORY_RETURNS => 'Returns',
            self::CATEGORY_SETTINGS => 'Settings',
            self::CATEGORY_BILLING => 'Billing',
        ];

        return $map[$category ?? ''] ?? '—';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(): array
    {
        return array_map(
            fn (string $value) => ['value' => $value, 'label' => self::categoryLabel($value)],
            self::CATEGORIES
        );
    }
}
