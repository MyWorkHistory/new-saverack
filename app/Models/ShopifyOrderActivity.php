<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyOrderActivity extends Model
{
    public const TYPE_CREATED = 'order_created';

    public const TYPE_IMPORTED = 'order_imported';

    public const TYPE_HOLD = 'order_hold';

    public const TYPE_CANCEL = 'order_cancel';

    public const TYPE_FULFILL = 'order_fulfill';

    public const TYPE_EDITED = 'order_edited';

    public const TYPE_ADDRESS = 'address_updated';

    public const TYPE_SHIPPING = 'shipping_updated';

    public const TYPE_ITEMS = 'items_updated';

    public const TYPE_READY = 'ready_to_ship';

    public const TYPE_SHOPIFY_EDIT = 'shopify_edit';

    public const TYPE_SYNC = 'synced';

    public const TYPE_RESHIP = 'reship';

    public const TYPE_REPROCESS = 'reprocess';

    protected $table = 'shopify_order_activities';

    protected $fillable = [
        'shopify_order_id',
        'type',
        'title',
        'detail',
        'meta',
        'actor_user_id',
        'actor_label',
    ];

    protected $casts = [
        'shopify_order_id' => 'integer',
        'actor_user_id' => 'integer',
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'shopify_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
