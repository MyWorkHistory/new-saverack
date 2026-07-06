<?php

namespace App\Services;

use App\Models\User;
use App\Models\WholesaleOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WholesaleOrderShipHeroService
{
    public function submitToShipHero(
        WholesaleOrder $order,
        ShipHeroOrderService $orders,
        User $actor
    ): array {
        $order->loadMissing(['clientAccount', 'lines']);

        if (! in_array($order->status, [WholesaleOrder::STATUS_DRAFT, WholesaleOrder::STATUS_PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or pending wholesale orders can be marked ready to ship.'],
            ]);
        }

        if ($order->shiphero_order_id) {
            throw ValidationException::withMessages([
                'status' => ['This wholesale order has already been submitted to ShipHero.'],
            ]);
        }

        if (! $order->isReadyToShipEligible()) {
            throw ValidationException::withMessages([
                'order' => ['Complete shipping address, carrier, method, requirements, and add line items before ready to ship.'],
            ]);
        }

        $account = $order->clientAccount;
        if ($account === null) {
            throw new RuntimeException('Client account not found.');
        }

        $customerId = trim((string) ($account->shiphero_customer_account_id ?? ''));
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => [
                    'This client account has no ShipHero customer account ID. Set it on the account profile, then try again.',
                ],
            ]);
        }

        $ship = is_array($order->shipping_address) ? $order->shipping_address : [];
        $carrier = trim((string) ($order->shipping_carrier ?? ''));
        $method = trim((string) ($order->shipping_method ?? ''));

        $linePayload = [];
        foreach ($order->lines as $line) {
            $sku = trim((string) $line->sku);
            if ($sku === '') {
                continue;
            }
            $linePayload[] = [
                'sku' => $sku,
                'quantity' => max(1, (int) $line->quantity),
                'price' => 0,
                'product_name' => (string) $line->name,
            ];
        }
        if ($linePayload === []) {
            throw ValidationException::withMessages([
                'lines' => ['Add at least one line item before marking ready to ship.'],
            ]);
        }

        $shopName = trim((string) ($account->company_name ?? ''));
        if ($shopName === '') {
            $shopName = 'Wholesale';
        }

        $created = $orders->createOrder($customerId, [
            'order_number' => (string) $order->order_number,
            'shop_name' => $shopName,
            'shipping_address' => $ship,
            'line_items' => $linePayload,
        ]);

        $shipheroOrderId = trim((string) ($created['shiphero_order_id'] ?? ''));
        if ($shipheroOrderId === '') {
            throw new RuntimeException('ShipHero did not return an order ID.');
        }

        $orders->updateOrderShippingLines($shipheroOrderId, $customerId, $carrier, $method);

        $packingNote = $this->buildPackingNote($order);
        if ($packingNote !== '') {
            $orders->updateOrderPackingNote($shipheroOrderId, $customerId, $packingNote);
        }

        try {
            $orders->updateOrderFulfillmentStatus(
                $shipheroOrderId,
                $customerId,
                'wholesale',
                'Wholesale order submitted via Save Rack CRM.'
            );
        } catch (RuntimeException $e) {
            Log::warning('wholesale.order.fulfillment_status_wholesale_failed', [
                'wholesale_order_id' => $order->id,
                'shiphero_order_id' => $shipheroOrderId,
                'message' => $e->getMessage(),
            ]);
            $orders->updateOrderTags($shipheroOrderId, $customerId, ['wholesale']);
        }

        $actorName = trim((string) ($actor->name ?? ''));
        if ($actorName === '') {
            $actorName = 'CRM user';
        }
        $orders->addOrderHistoryEntry(
            $shipheroOrderId,
            $customerId,
            'Wholesale order submitted by '.$actorName.' via Save Rack.',
            'Save Rack CRM'
        );

        $order->shiphero_order_id = $shipheroOrderId;
        $order->status = WholesaleOrder::STATUS_IN_PROGRESS;
        $order->save();

        return [
            'shiphero_order_id' => $shipheroOrderId,
            'order_number' => (string) ($created['order_number'] ?? $order->order_number),
            'client_account_id' => (int) $order->client_account_id,
        ];
    }

    private function buildPackingNote(WholesaleOrder $order): string
    {
        $parts = [];
        $instructions = trim((string) ($order->instructions ?? ''));
        if ($instructions !== '') {
            $parts[] = $instructions;
        }

        $reqBlock = $this->formatRequirementsBlock($order);
        if ($reqBlock !== '') {
            $parts[] = $reqBlock;
        }

        return trim(implode("\n\n", $parts));
    }

    private function formatRequirementsBlock(WholesaleOrder $order): string
    {
        /** @var array<string, string> $barcodeLabels */
        $barcodeLabels = config('wholesale_orders.sku_barcode_labels', []);
        /** @var array<string, string> $coverExisting */
        $coverExisting = config('wholesale_orders.cover_existing_barcodes', []);
        /** @var array<string, string> $packaging */
        $packaging = config('wholesale_orders.individual_sku_packaging', []);
        /** @var array<string, string> $bundle */
        $bundle = config('wholesale_orders.bundle_configuration', []);
        /** @var array<string, string> $shipMethod */
        $shipMethod = config('wholesale_orders.shipping_method_requirement', []);
        /** @var array<string, string> $masterCartons */
        $masterCartons = config('wholesale_orders.master_cartons', []);

        $lines = ['--- Wholesale Requirements ---'];

        $skuLabels = (string) ($order->sku_barcode_labels ?? '');
        if ($skuLabels !== '') {
            $lines[] = 'SKU Barcode Labels: '.($barcodeLabels[$skuLabels] ?? $skuLabels);
            $comment = trim((string) ($order->sku_barcode_labels_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        $cover = (string) ($order->cover_existing_barcodes ?? '');
        if ($cover !== '') {
            $lines[] = 'Cover Existing Barcodes: '.($coverExisting[$cover] ?? $cover);
            $comment = trim((string) ($order->cover_existing_barcodes_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        $pack = (string) ($order->individual_sku_packaging ?? '');
        if ($pack !== '') {
            $lines[] = 'Individual SKU Packaging: '.($packaging[$pack] ?? $pack);
            $comment = trim((string) ($order->individual_sku_packaging_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        $bundleCfg = (string) ($order->bundle_configuration ?? '');
        if ($bundleCfg !== '') {
            $lines[] = 'Bundle Configuration: '.($bundle[$bundleCfg] ?? $bundleCfg);
            $comment = trim((string) ($order->bundle_configuration_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        $shipReq = (string) ($order->shipping_method_requirement ?? '');
        if ($shipReq !== '') {
            $lines[] = 'Shipping Method: '.($shipMethod[$shipReq] ?? $shipReq);
            $comment = trim((string) ($order->shipping_method_requirement_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        $cartons = (string) ($order->master_cartons ?? '');
        if ($cartons !== '') {
            $lines[] = 'Master Cartons: '.($masterCartons[$cartons] ?? $cartons);
            $comment = trim((string) ($order->master_cartons_comment ?? ''));
            if ($comment !== '') {
                $lines[] = '  Comments: '.$comment;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }
}
