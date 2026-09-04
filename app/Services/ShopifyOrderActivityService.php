<?php

namespace App\Services;

use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderActivity;
use App\Models\User;

class ShopifyOrderActivityService
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        ShopifyOrder $order,
        string $type,
        string $title,
        ?string $detail = null,
        ?User $actor = null,
        ?string $actorLabel = null,
        ?array $meta = null
    ): ShopifyOrderActivity {
        $label = $actorLabel;
        if ($label === null || $label === '') {
            if ($actor !== null) {
                $label = trim((string) ($actor->name ?? '')) ?: 'User';
            } else {
                $label = 'System';
            }
        }

        return ShopifyOrderActivity::query()->create([
            'shopify_order_id' => $order->id,
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'meta' => $meta,
            'actor_user_id' => $actor?->id,
            'actor_label' => $label,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timelineFor(ShopifyOrder $order): array
    {
        $rows = ShopifyOrderActivity::query()
            ->where('shopify_order_id', $order->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        if ($rows->isEmpty() && $order->shopify_created_at !== null) {
            $this->record(
                $order,
                ShopifyOrderActivity::TYPE_IMPORTED,
                'Order imported from Shopify',
                null,
                null,
                'System'
            );
            $rows = ShopifyOrderActivity::query()
                ->where('shopify_order_id', $order->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }

        return $rows->map(static function (ShopifyOrderActivity $row) {
            return [
                'id' => $row->id,
                'type' => $row->type,
                'title' => $row->title,
                'detail' => $row->detail,
                'meta' => $row->meta,
                'actor_label' => $row->actor_label,
                'actor_user_id' => $row->actor_user_id,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }
}
