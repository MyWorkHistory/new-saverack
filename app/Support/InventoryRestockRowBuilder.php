<?php

namespace App\Support;

/**
 * Build restock report rows: pickable locations only, pickable qty within threshold.
 */
class InventoryRestockRowBuilder
{
    /**
     * @param  list<array{location_name: ?string, quantity: int, pickable: ?bool}>  $locations
     * @return array<string, mixed>|null
     */
    public static function buildRow(
        string $sku,
        string $name,
        ?string $imageUrl,
        array $locations,
        int $maxPickableQty = 2
    ): ?array {
        $pickQty = 0;
        $pickNames = [];
        $hasPickableLocation = false;

        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            if (($loc['pickable'] ?? null) !== true) {
                continue;
            }
            $hasPickableLocation = true;
            $qty = max(0, (int) ($loc['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $pickQty += $qty;
            $locName = trim((string) ($loc['location_name'] ?? ''));
            if ($locName === '') {
                $locName = '—';
            }
            if (! in_array($locName, $pickNames, true)) {
                $pickNames[] = $locName;
            }
        }

        if (! $hasPickableLocation) {
            return null;
        }

        if ($pickQty > max(0, $maxPickableQty)) {
            return null;
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'image_url' => $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null,
            'pick_location' => $pickNames !== [] ? implode(', ', $pickNames) : '—',
            'pick_qty' => $pickQty,
        ];
    }
}
