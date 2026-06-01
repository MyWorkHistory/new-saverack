<?php

namespace App\Support;

/**
 * Build restock report rows from enriched warehouse product locations.
 */
class InventoryRestockRowBuilder
{
    public const PICK_QTY_THRESHOLD = 2;

    /**
     * @param  list<array{location_name: ?string, quantity: int, pickable: ?bool}>  $locations
     * @return array<string, mixed>|null
     */
    public static function buildRow(
        string $sku,
        string $name,
        ?string $imageUrl,
        array $locations
    ): ?array {
        $pickQty = 0;
        $backstockQty = 0;
        $pickNames = [];
        $nonPickableWithQty = [];

        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $qty = max(0, (int) ($loc['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $pickable = $loc['pickable'] ?? null;
            $locName = trim((string) ($loc['location_name'] ?? ''));
            if ($locName === '') {
                $locName = '—';
            }

            if ($pickable === true) {
                $pickQty += $qty;
                if (! in_array($locName, $pickNames, true)) {
                    $pickNames[] = $locName;
                }
            } elseif ($pickable === false) {
                $backstockQty += $qty;
                $nonPickableWithQty[] = [
                    'name' => $locName,
                    'quantity' => $qty,
                ];
            }
        }

        if ($pickQty > self::PICK_QTY_THRESHOLD || $backstockQty <= 0) {
            return null;
        }

        $backstockLocation = self::lowestQtyLocationLabel($nonPickableWithQty);

        return [
            'sku' => $sku,
            'name' => $name,
            'image_url' => $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null,
            'pick_location' => $pickNames !== [] ? implode(', ', $pickNames) : '—',
            'pick_qty' => $pickQty,
            'backstock_qty' => $backstockQty,
            'backstock_location' => $backstockLocation,
        ];
    }

    /**
     * @param  list<array{name: string, quantity: int}>  $locations
     */
    public static function lowestQtyLocationLabel(array $locations): string
    {
        if ($locations === []) {
            return '—';
        }
        usort($locations, static fn (array $a, array $b): int => ($a['quantity'] <=> $b['quantity']) ?: strcmp($a['name'], $b['name']));
        $low = $locations[0];

        return $low['name'].' ('.$low['quantity'].')';
    }
}
