<?php

namespace App\Support;

/**
 * Build restock report rows: low pickable qty with stock in non-pickable (backstock) locations.
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
        $backstockQty = 0;
        $pickNames = [];
        $nonPickableWithQty = [];
        $hasPickableLocation = false;

        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $qty = max(0, (int) ($loc['quantity'] ?? 0));
            $pickable = $loc['pickable'] ?? null;
            $locName = trim((string) ($loc['location_name'] ?? ''));
            if ($locName === '') {
                $locName = '—';
            }

            if ($pickable === true) {
                $hasPickableLocation = true;
                if ($qty > 0) {
                    $pickQty += $qty;
                }
                // Include pick bins even at 0 qty so restock UI knows transfer destinations.
                if ($locName !== '—' && ! in_array($locName, $pickNames, true)) {
                    $pickNames[] = $locName;
                }
            } elseif ($pickable === false) {
                if ($qty <= 0) {
                    continue;
                }
                $backstockQty += $qty;
                $nonPickableWithQty[] = [
                    'name' => $locName,
                    'quantity' => $qty,
                ];
            }
        }

        if (! $hasPickableLocation || $backstockQty <= 0) {
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
            'backstock_qty' => $backstockQty,
            'backstock_location' => self::lowestQtyLocationLabel($nonPickableWithQty),
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
