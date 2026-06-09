<?php

namespace App\Support;

use App\Services\AsnReceivingService;

class PutAwayRowBuilder
{
    /**
     * @param  list<array<string, mixed>>  $locations
     * @return array<string, mixed>
     */
    public static function buildRow(
        string $sku,
        string $name,
        ?string $barcode,
        ?string $imageUrl,
        array $locations,
        int $onHand,
        int $backorder
    ): array {
        $receiving = 0;
        $pickable = 0;
        $nonPickable = 0;
        $receivingName = strtolower(AsnReceivingService::RECEIVING_LOCATION_NAME);

        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $qty = max(0, (int) ($loc['quantity'] ?? 0));
            $locName = strtolower(trim((string) ($loc['location_name'] ?? '')));
            if ($locName === $receivingName) {
                $receiving += $qty;
            }
            $pick = $loc['pickable'] ?? null;
            if ($pick === true) {
                $pickable += $qty;
            } elseif ($pick === false) {
                $nonPickable += $qty;
            }
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'barcode' => $barcode !== null && trim($barcode) !== '' ? trim($barcode) : null,
            'image_url' => $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null,
            'receiving_qty' => $receiving,
            'pickable_qty' => $pickable,
            'non_pickable_qty' => $nonPickable,
            'on_hand' => max(0, $onHand),
            'backorder' => max(0, $backorder),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function locationsFromProductDetail(?array $product): array
    {
        if ($product === null || ! is_array($product['warehouses'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($product['warehouses'] as $wh) {
            if (! is_array($wh)) {
                continue;
            }
            foreach ($wh['locations'] ?? [] as $loc) {
                if (! is_array($loc)) {
                    continue;
                }
                $out[] = $loc;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     */
    public static function receivingQtyFromLocations(array $locations): int
    {
        $receivingName = strtolower(AsnReceivingService::RECEIVING_LOCATION_NAME);
        $total = 0;
        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $locName = strtolower(trim((string) ($loc['location_name'] ?? '')));
            if ($locName === $receivingName) {
                $total += max(0, (int) ($loc['quantity'] ?? 0));
            }
        }

        return $total;
    }
}
