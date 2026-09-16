<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ShopifyPackagingItem extends Model
{
    public const CATEGORY_PACKAGING = 'packaging';

    public const CATEGORY_MATERIALS = 'packaging_materials';

    /** @var array<string, string> */
    public const CATEGORIES = [
        self::CATEGORY_PACKAGING => 'Packaging',
        self::CATEGORY_MATERIALS => 'Packaging Materials',
    ];

    /** @var array<string, array<string, string>> */
    public const TYPES = [
        self::CATEGORY_PACKAGING => [
            'box' => 'Box',
            'poly_mailer' => 'Poly Mailer',
            'bubble_mailer' => 'Bubble Mailer',
            'kraft_mailer' => 'Kraft Mailer',
        ],
        self::CATEGORY_MATERIALS => [
            'kraft_paper' => 'Kraft Paper',
            'bubble_wrap' => 'Bubble Wrap',
            'peanuts' => 'Peanuts',
            'tissue_paper' => 'Tissue Paper',
        ],
    ];

    protected $table = 'shopify_packaging_items';

    protected $fillable = [
        'name',
        'sku',
        'category',
        'type',
        'cost_cents',
        'price_cents',
        'on_hand',
        'length',
        'width',
        'height',
        'weight',
        'image_path',
    ];

    protected $casts = [
        'cost_cents' => 'integer',
        'price_cents' => 'integer',
        'on_hand' => 'integer',
        'length' => 'float',
        'width' => 'float',
        'height' => 'float',
        'weight' => 'float',
    ];

    /**
     * @return array<string, string>
     */
    public static function typesFor(string $category): array
    {
        return self::TYPES[$category] ?? [];
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function typeLabel(): string
    {
        $types = self::typesFor((string) $this->category);

        return $types[$this->type] ?? $this->type;
    }

    public function imageUrl(): ?string
    {
        $path = trim((string) ($this->image_path ?? ''));
        if ($path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function cubicFeet(): ?float
    {
        if ($this->length === null || $this->width === null || $this->height === null) {
            return null;
        }
        if ($this->length <= 0 || $this->width <= 0 || $this->height <= 0) {
            return null;
        }

        return round(($this->length * $this->width * $this->height) / 1728, 3);
    }
}
