<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supply extends Model
{
    public const TYPE_BOX = 'box';

    public const TYPE_POLY_MAILER = 'poly_mailer';

    public const TYPE_BUBBLE_MAILER = 'bubble_mailer';

    public const TYPE_KRAFT_MAILER = 'kraft_mailer';

    public const TYPE_PACKAGING_MATERIALS = 'packaging_materials';

    public const TYPE_OFFICE_SUPPLIES = 'office_supplies';

    public const TYPE_WAREHOUSE_SUPPLIES = 'warehouse_supplies';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BOX,
        self::TYPE_POLY_MAILER,
        self::TYPE_BUBBLE_MAILER,
        self::TYPE_KRAFT_MAILER,
        self::TYPE_PACKAGING_MATERIALS,
        self::TYPE_OFFICE_SUPPLIES,
        self::TYPE_WAREHOUSE_SUPPLIES,
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_BOX => 'Box',
        self::TYPE_POLY_MAILER => 'Poly Mailer',
        self::TYPE_BUBBLE_MAILER => 'Bubble Mailer',
        self::TYPE_KRAFT_MAILER => 'Kraft Mailer',
        self::TYPE_PACKAGING_MATERIALS => 'Packaging Materials',
        self::TYPE_OFFICE_SUPPLIES => 'Office Supplies',
        self::TYPE_WAREHOUSE_SUPPLIES => 'Warehouse Supplies',
    ];

    protected $fillable = [
        'type',
        'name',
        'link',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public static function typeLabel(?string $type): string
    {
        $key = (string) $type;

        return self::TYPE_LABELS[$key] ?? (trim($key) !== '' ? $key : '—');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(SupplyOrderLine::class);
    }
}
