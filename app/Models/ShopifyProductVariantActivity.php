<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyProductVariantActivity extends Model
{
    public const TYPE_CREATED = 'product_created';

    public const TYPE_NAME = 'product_name_updated';

    public const TYPE_SKU = 'sku_updated';

    public const TYPE_BARCODE = 'barcode_updated';

    public const TYPE_WEIGHT = 'weight_updated';

    public const TYPE_DIMENSIONS = 'dimensions_updated';

    public const TYPE_PRODUCT_TYPE = 'product_type_updated';

    public const TYPE_BUNDLE_UPDATED = 'bundle_updated';

    public const TYPE_SYNC_INFO = 'product_info_synced';

    public const TYPE_PUSH_INVENTORY = 'inventory_pushed';

    protected $table = 'shopify_product_variant_activities';

    protected $fillable = [
        'shopify_variant_id',
        'type',
        'title',
        'detail',
        'meta',
        'actor_user_id',
        'actor_label',
    ];

    protected $casts = [
        'shopify_variant_id' => 'integer',
        'actor_user_id' => 'integer',
        'meta' => 'array',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductVariant::class, 'shopify_variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
