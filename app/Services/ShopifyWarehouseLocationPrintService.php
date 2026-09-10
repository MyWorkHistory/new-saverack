<?php

namespace App\Services;

use App\Models\ShopifyWarehouseLocation;
use App\Models\ShopifyWarehouseLocationItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ShopifyWarehouseLocationPrintService
{
    /**
     * Full-page inventory sheet for a warehouse location.
     *
     * @param  array{q?: string, client_account_id?: int}  $filters
     */
    public function streamInventoryPdf(ShopifyWarehouseLocation $location, array $filters = []): Response
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $accountId = (int) ($filters['client_account_id'] ?? 0);

        $itemsQuery = ShopifyWarehouseLocationItem::query()
            ->where('location_id', $location->id)
            ->where('available', '>', 0)
            ->with(['variant.product', 'variant.connection.clientAccount']);

        if ($accountId > 0) {
            $itemsQuery->whereHas('variant.connection', function ($builder) use ($accountId) {
                $builder->where('client_account_id', $accountId);
            });
        }

        if ($q !== '') {
            $itemsQuery->where(function ($builder) use ($q) {
                $builder->whereHas('variant', function ($v) use ($q) {
                    $v->where('sku', 'like', '%'.$q.'%')
                        ->orWhere('title', 'like', '%'.$q.'%');
                })->orWhereHas('variant.product', function ($p) use ($q) {
                    $p->where('title', 'like', '%'.$q.'%');
                });
            });
        }

        $rows = $itemsQuery
            ->orderByDesc('available')
            ->orderBy('id')
            ->get();

        $items = [];
        $totalQty = 0;
        foreach ($rows as $item) {
            /** @var ShopifyWarehouseLocationItem $item */
            $variant = $item->variant;
            $product = $variant ? $variant->product : null;
            $connection = $variant ? $variant->connection : null;
            $account = $connection ? $connection->clientAccount : null;
            $available = (int) $item->available;
            $totalQty += $available;

            $title = trim((string) (
                ($product ? ($product->title ?? '') : null)
                ?? ($variant ? ($variant->title ?? '') : null)
                ?? ''
            ));
            $sku = trim((string) ($variant ? ($variant->sku ?? '') : ''));
            $accountName = trim((string) ($account ? ($account->company_name ?? '') : ''));

            $items[] = [
                'product_title' => $title !== '' ? $title : '—',
                'sku' => $sku,
                'account_name' => $accountName !== '' ? $accountName : '—',
                'available' => $available,
            ];
        }

        $locationName = trim((string) ($location->name ?? ''));
        if ($locationName === '') {
            $locationName = 'Location #'.$location->id;
        }

        $pdf = Pdf::loadView('pdf.shopify.warehouse-location-inventory', [
            'locationName' => $locationName,
            'items' => $items,
            'totalQty' => $totalQty,
        ])->setPaper('letter', 'portrait');

        $safeSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $locationName) ?: 'location';
        $filename = 'location-'.$safeSlug.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
