<?php

namespace App\Support;

/**
 * Normalize ShipHero order shipment labels for CRM order detail (shipped orders).
 */
class OrderShipmentTracking
{
    /**
     * @param  list<array<string, mixed>>  $shipments  order.data.shipments from ShipHero
     * @return array{labels: list<array<string, mixed>>, total_label_cost: float|null}
     */
    public static function fromShipHeroShipments(array $shipments): array
    {
        $labels = [];
        $totalCost = 0.0;
        $hasCost = false;

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }
            $rawLabels = $shipment['shipping_labels'] ?? null;
            if (! is_array($rawLabels)) {
                continue;
            }
            foreach ($rawLabels as $label) {
                if (! is_array($label)) {
                    continue;
                }
                if (self::isVoidShippingLabel($label)) {
                    continue;
                }
                $trackingNumber = trim((string) ($label['tracking_number'] ?? ''));
                if ($trackingNumber === '') {
                    continue;
                }
                $carrier = trim((string) ($label['carrier'] ?? ''));
                $shippingName = trim((string) ($label['shipping_name'] ?? ''));
                $shippingMethod = trim((string) ($label['shipping_method'] ?? ''));
                $serviceLabel = self::buildServiceLabel($carrier, $shippingName, $shippingMethod);
                $cost = self::parseLabelCost($label['cost'] ?? null);
                if ($cost !== null) {
                    $totalCost += $cost;
                    $hasCost = true;
                }
                $labels[] = [
                    'id' => (string) ($label['id'] ?? $trackingNumber),
                    'service_label' => $serviceLabel,
                    'tracking_number' => $trackingNumber,
                    'tracking_url' => self::buildTrackingUrl(
                        $carrier,
                        $shippingName,
                        $trackingNumber,
                        isset($label['tracking_url']) ? (string) $label['tracking_url'] : null
                    ),
                    'cost' => $cost,
                ];
            }
        }

        return [
            'labels' => $labels,
            'total_label_cost' => $hasCost ? round($totalCost, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $label
     */
    public static function isVoidShippingLabel(array $label): bool
    {
        $status = strtolower(trim((string) ($label['status'] ?? '')));
        if ($status === '') {
            return false;
        }

        return strpos($status, 'void') !== false;
    }

    public static function buildServiceLabel(string $carrier, string $shippingName, string $shippingMethod): string
    {
        $name = trim($shippingName);
        if ($name !== '') {
            return self::displayCarrierText($name);
        }
        $carrierDisplay = self::displayCarrierText(self::formatCarrierSlug($carrier));
        $method = trim($shippingMethod);
        if ($method !== '' && ! preg_match('/^\d+$/', $method)) {
            $methodDisplay = self::displayCarrierText($method);

            return trim($carrierDisplay.' '.$methodDisplay);
        }

        return $carrierDisplay !== '' ? $carrierDisplay : 'Shipment';
    }

    public static function buildTrackingUrl(
        string $carrier,
        string $shippingName,
        string $trackingNumber,
        ?string $shipHeroTrackingUrl
    ): ?string {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return null;
        }
        if (self::isUspsFamily($carrier, $shippingName)) {
            return 'https://tools.usps.com/tracking/?strOrigTrackNum='.rawurlencode($trackingNumber);
        }
        $url = trim((string) $shipHeroTrackingUrl);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return null;
    }

    private static function isUspsFamily(string $carrier, string $shippingName): bool
    {
        $blob = strtolower(trim($carrier.' '.$shippingName));

        return strpos($blob, 'usps') !== false || strpos($blob, 'endicia') !== false;
    }

    private static function displayCarrierText(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        return (string) (preg_replace('/\bendicia\b/i', 'USPS', $trimmed) ?? $trimmed);
    }

    private static function formatCarrierSlug(string $carrier): string
    {
        $raw = trim($carrier);
        if ($raw === '') {
            return '';
        }
        $lower = strtolower($raw);
        if ($lower === 'ups') {
            return 'UPS';
        }
        if ($lower === 'fedex') {
            return 'FedEx';
        }
        if ($lower === 'usps' || $lower === 'endicia') {
            return 'USPS';
        }
        if ($lower === 'dhl') {
            return 'DHL';
        }

        return self::displayCarrierText($raw);
    }

    private static function parseLabelCost($cost): ?float
    {
        if ($cost === null || $cost === '') {
            return null;
        }
        if (! is_numeric($cost)) {
            return null;
        }

        return round((float) $cost, 2);
    }
}
