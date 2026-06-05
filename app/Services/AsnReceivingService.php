<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AsnReceivingService
{
    public const RECEIVING_LOCATION_NAME = 'Receiving';

    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
    }

    public static function nextAsnNumber(): string
    {
        $max = 0;
        $numbers = ClientAccountAsn::query()->pluck('asn_number');
        foreach ($numbers as $raw) {
            $s = trim((string) $raw);
            if ($s === '' || $s === 'TMP') {
                continue;
            }
            if (preg_match('/^(\d{1,4})$/', $s, $m)) {
                $max = max($max, (int) $m[1]);

                continue;
            }
            if (preg_match('/(\d+)$/', $s, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeLine(ClientAccountAsnLine $line): array
    {
        return [
            'id' => $line->id,
            'shiphero_product_id' => $line->shiphero_product_id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $line->image_url,
            'expected_qty' => $line->expected_qty,
            'accepted_qty' => $line->accepted_qty,
            'rejected_qty' => $line->rejected_qty,
            'line_status' => $line->line_status ?: self::computeLineStatus($line),
            'barcode' => $line->barcode,
            'weight' => $this->displaySpec($line->weight),
            'length' => $this->displaySpec($line->length),
            'width' => $this->displaySpec($line->width),
            'height' => $this->displaySpec($line->height),
            'specs_cached_at' => optional($line->specs_cached_at)->toIso8601String(),
            'sort_order' => $line->sort_order,
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function displaySpec($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (float) $value;
        if ($n <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    public static function computeLineStatus(ClientAccountAsnLine $line): string
    {
        $expected = (int) $line->expected_qty;
        $accepted = (int) $line->accepted_qty;
        $rejected = (int) $line->rejected_qty;
        $sum = $accepted + $rejected;
        if ($sum <= 0) {
            return ClientAccountAsnLine::LINE_STATUS_PENDING;
        }
        if ($expected > 0 && $sum >= $expected) {
            return ClientAccountAsnLine::LINE_STATUS_COMPLETED;
        }

        return ClientAccountAsnLine::LINE_STATUS_PARTIAL;
    }

    public function syncLineStatus(ClientAccountAsnLine $line): void
    {
        $line->line_status = self::computeLineStatus($line);
        $line->saveQuietly();
    }

    public function recalcAsnAggregates(ClientAccountAsn $asn): void
    {
        $sums = ClientAccountAsnLine::query()
            ->where('client_account_asn_id', $asn->id)
            ->selectRaw('COALESCE(SUM(expected_qty),0) as e, COALESCE(SUM(accepted_qty),0) as a, COALESCE(SUM(rejected_qty),0) as r')
            ->first();
        $asn->expected_qty = (int) ($sums->e ?? 0);
        $asn->accepted_qty = (int) ($sums->a ?? 0);
        $asn->rejected_qty = (int) ($sums->r ?? 0);
        $asn->saveQuietly();
    }

    public function applyAutoAsnStatus(ClientAccountAsn $asn): void
    {
        if ($asn->status === ClientAccountAsn::STATUS_NON_COMPLIANT) {
            return;
        }
        if ($asn->status === ClientAccountAsn::STATUS_DRAFT) {
            return;
        }

        $asn->loadMissing('lines');
        if ($asn->lines->isEmpty()) {
            return;
        }

        $hasActivity = false;
        $allCompleted = true;
        foreach ($asn->lines as $line) {
            $status = self::computeLineStatus($line);
            if ($status !== ClientAccountAsnLine::LINE_STATUS_PENDING) {
                $hasActivity = true;
            }
            if ($status !== ClientAccountAsnLine::LINE_STATUS_COMPLETED) {
                $allCompleted = false;
            }
        }

        if ($hasActivity && $asn->status === ClientAccountAsn::STATUS_PENDING) {
            $asn->status = ClientAccountAsn::STATUS_IN_PROGRESS;
        }
        if ($allCompleted && $hasActivity) {
            $asn->status = ClientAccountAsn::STATUS_COMPLETED;
        } elseif ($hasActivity && $asn->status !== ClientAccountAsn::STATUS_COMPLETED) {
            $asn->status = ClientAccountAsn::STATUS_IN_PROGRESS;
        }
        $asn->saveQuietly();
    }

    public function markProcessedIfNeeded(ClientAccountAsn $asn, ?User $actor = null): void
    {
        if ($asn->processed_at !== null) {
            return;
        }
        if ((int) $asn->accepted_qty > 0) {
            $asn->processed_at = now();
            if ($actor !== null) {
                $asn->processed_by_user_id = $actor->id;
            }
            $asn->saveQuietly();
        }
    }

    /**
     * @return int Current on-hand at Receiving for SKU
     */
    public function receivingOnHandForSku(ClientAccount $account, string $sku): int
    {
        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            return 0;
        }
        $product = $this->inventory->getProductDetailBySku($sku, null, $customerId);
        if (! is_array($product)) {
            return 0;
        }

        return $this->quantityAtLocationName($product, self::RECEIVING_LOCATION_NAME);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function quantityAtLocationName(array $product, string $locationName): int
    {
        $needle = strtolower(trim($locationName));
        foreach ($product['warehouses'] ?? [] as $wh) {
            if (! is_array($wh)) {
                continue;
            }
            foreach ($wh['locations'] ?? [] as $loc) {
                if (! is_array($loc)) {
                    continue;
                }
                $name = strtolower(trim((string) ($loc['location_name'] ?? '')));
                if ($name === $needle) {
                    return max(0, (int) ($loc['quantity'] ?? 0));
                }
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function skuHasLocationNamed(array $product, string $locationName): bool
    {
        $needle = strtolower(trim($locationName));
        foreach ($product['warehouses'] ?? [] as $wh) {
            if (! is_array($wh)) {
                continue;
            }
            foreach ($wh['locations'] ?? [] as $loc) {
                if (! is_array($loc)) {
                    continue;
                }
                $name = strtolower(trim((string) ($loc['location_name'] ?? '')));
                if ($name === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{warehouse_id: string, location_id: string}
     */
    public function resolveReceivingLocation(ClientAccount $account, string $sku): array
    {
        $customerId = trim((string) $account->shiphero_customer_account_id);
        $product = $this->inventory->getProductDetailBySku($sku, null, $customerId !== '' ? $customerId : null);
        if (! is_array($product)) {
            throw ValidationException::withMessages([
                'sku' => ['Product not found in ShipHero for this account.'],
            ]);
        }
        $warehouseId = '';
        foreach ($product['warehouses'] ?? [] as $wh) {
            if (is_array($wh) && ! empty($wh['warehouse_id'])) {
                $warehouseId = (string) $wh['warehouse_id'];
                break;
            }
        }
        if ($warehouseId === '') {
            throw ValidationException::withMessages([
                'sku' => ['No warehouse found for this SKU.'],
            ]);
        }
        $resolved = $this->inventory->ensureWarehouseLocation(
            $warehouseId,
            self::RECEIVING_LOCATION_NAME,
            $customerId !== '' ? $customerId : null
        );
        if (! is_array($resolved) || empty($resolved['id'])) {
            throw ValidationException::withMessages([
                'location' => ['Receiving location not found in ShipHero for this warehouse.'],
            ]);
        }

        return [
            'warehouse_id' => $warehouseId,
            'location_id' => (string) $resolved['id'],
        ];
    }

    public function incrementReceivingInventory(
        ClientAccount $account,
        string $sku,
        int $delta,
        string $reason = 'ASN receiving increment'
    ): void {
        if ($delta === 0) {
            return;
        }
        if ($delta < 0) {
            throw ValidationException::withMessages([
                'delta' => ['Quantity increment must be zero or greater.'],
            ]);
        }
        $customerId = trim((string) $account->shiphero_customer_account_id);
        $product = $this->inventory->getProductDetailBySku($sku, null, $customerId !== '' ? $customerId : null);
        $resolved = $this->resolveReceivingLocation($account, $sku);
        $customer = $customerId !== '' ? $customerId : null;

        if (is_array($product) && ! $this->skuHasLocationNamed($product, self::RECEIVING_LOCATION_NAME)) {
            $this->inventory->addLocationQuantity(
                $sku,
                $resolved['warehouse_id'],
                $resolved['location_id'],
                $delta,
                $reason,
                $customer
            );

            return;
        }

        $current = is_array($product)
            ? $this->quantityAtLocationName($product, self::RECEIVING_LOCATION_NAME)
            : $this->receivingOnHandForSku($account, $sku);
        $this->inventory->replaceLocationQuantity(
            $sku,
            $resolved['warehouse_id'],
            $resolved['location_id'],
            $current + $delta,
            $reason,
            $customer
        );
    }

    public function setReceivingInventoryAbsolute(
        ClientAccount $account,
        string $sku,
        int $quantity,
        string $reason = 'ASN receiving override'
    ): void {
        if ($quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be zero or greater.'],
            ]);
        }
        $customerId = trim((string) $account->shiphero_customer_account_id);
        $product = $this->inventory->getProductDetailBySku($sku, null, $customerId !== '' ? $customerId : null);
        $resolved = $this->resolveReceivingLocation($account, $sku);
        $customer = $customerId !== '' ? $customerId : null;

        if (
            $quantity > 0
            && is_array($product)
            && ! $this->skuHasLocationNamed($product, self::RECEIVING_LOCATION_NAME)
        ) {
            $this->inventory->addLocationQuantity(
                $sku,
                $resolved['warehouse_id'],
                $resolved['location_id'],
                $quantity,
                $reason,
                $customer
            );

            return;
        }

        $this->inventory->replaceLocationQuantity(
            $sku,
            $resolved['warehouse_id'],
            $resolved['location_id'],
            $quantity,
            $reason,
            $customer
        );
    }

    public function receiveIncrement(
        ClientAccountAsn $asn,
        ClientAccountAsnLine $line,
        int $delta,
        ?User $actor = null
    ): ClientAccountAsnLine {
        if ($delta <= 0) {
            throw ValidationException::withMessages([
                'delta' => ['Enter a quantity greater than zero.'],
            ]);
        }

        return DB::transaction(function () use ($asn, $line, $delta, $actor) {
            $account = $asn->clientAccount ?? ClientAccount::query()->findOrFail($asn->client_account_id);
            $this->incrementReceivingInventory($account, (string) $line->sku, $delta);
            $line->accepted_qty = (int) $line->accepted_qty + $delta;
            $line->save();
            $this->syncLineStatus($line);
            $this->recalcAsnAggregates($asn->fresh());
            $asn->refresh();
            $this->markProcessedIfNeeded($asn, $actor);
            $this->applyAutoAsnStatus($asn);

            return $line->fresh();
        });
    }

    public function receiveOverride(
        ClientAccountAsn $asn,
        ClientAccountAsnLine $line,
        int $newAcceptedQty,
        ?User $actor = null
    ): ClientAccountAsnLine {
        if ($newAcceptedQty < 0) {
            throw ValidationException::withMessages([
                'accepted_qty' => ['Quantity must be zero or greater.'],
            ]);
        }

        return DB::transaction(function () use ($asn, $line, $newAcceptedQty, $actor) {
            $account = $asn->clientAccount ?? ClientAccount::query()->findOrFail($asn->client_account_id);
            $this->setReceivingInventoryAbsolute($account, (string) $line->sku, $newAcceptedQty);
            $line->accepted_qty = $newAcceptedQty;
            $line->save();
            $this->syncLineStatus($line);
            $this->recalcAsnAggregates($asn->fresh());
            $asn->refresh();
            $this->markProcessedIfNeeded($asn, $actor);
            $this->applyAutoAsnStatus($asn);

            return $line->fresh();
        });
    }

    public function rejectOverride(ClientAccountAsnLine $line, int $newRejectedQty): ClientAccountAsnLine
    {
        if ($newRejectedQty < 0) {
            throw ValidationException::withMessages([
                'rejected_qty' => ['Quantity must be zero or greater.'],
            ]);
        }

        return DB::transaction(function () use ($line, $newRejectedQty) {
            $line->rejected_qty = $newRejectedQty;
            $line->save();
            $asn = $line->asn ?? ClientAccountAsn::query()->findOrFail($line->client_account_asn_id);
            $this->syncLineStatus($line);
            $this->recalcAsnAggregates($asn);
            $this->applyAutoAsnStatus($asn->fresh());

            return $line->fresh();
        });
    }

    /**
     * @param  list<string>  $barcodes
     * @return array{matched: int, unmatched: list<string>}
     */
    public function scanBarcodes(ClientAccountAsn $asn, array $barcodes, ?User $actor = null): array
    {
        $asn->loadMissing('lines');
        $matched = 0;
        $unmatched = [];
        $counts = [];
        foreach ($barcodes as $code) {
            $c = trim((string) $code);
            if ($c === '') {
                continue;
            }
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }

        DB::transaction(function () use ($asn, $counts, &$matched, &$unmatched, $actor) {
            foreach ($counts as $code => $qty) {
                $line = $this->findLineByBarcodeOrSku($asn, $code);
                if ($line === null) {
                    $unmatched[] = $code;

                    continue;
                }
                $this->receiveIncrement($asn, $line, (int) $qty, $actor);
                $matched += (int) $qty;
                $asn->refresh();
                $asn->load('lines');
            }
        });

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function findLineByBarcodeOrSku(ClientAccountAsn $asn, string $code): ?ClientAccountAsnLine
    {
        $needle = strtolower(trim($code));
        foreach ($asn->lines as $line) {
            if (strtolower(trim((string) $line->sku)) === $needle) {
                return $line;
            }
            if ($line->barcode !== null && strtolower(trim((string) $line->barcode)) === $needle) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @return array{enriched: int}
     */
    public function enrichLineSpecs(ClientAccountAsn $asn, bool $force = false): array
    {
        $asn->loadMissing(['lines', 'clientAccount']);
        $account = $asn->clientAccount;
        if ($account === null) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Client account not found.'],
            ]);
        }
        $customerId = trim((string) $account->shiphero_customer_account_id);
        $enriched = 0;
        foreach ($asn->lines as $line) {
            if (! $force && $line->specs_cached_at !== null) {
                continue;
            }
            $product = $this->inventory->getProductDetailBySku(
                (string) $line->sku,
                null,
                $customerId !== '' ? $customerId : null
            );
            if (! is_array($product)) {
                continue;
            }
            $dims = is_array($product['dimensions'] ?? null) ? $product['dimensions'] : [];
            $line->barcode = isset($product['barcode']) ? trim((string) $product['barcode']) : null;
            $line->weight = $this->numericOrNull($dims['weight'] ?? null);
            $line->length = $this->numericOrNull($dims['length'] ?? null);
            $line->width = $this->numericOrNull($dims['width'] ?? null);
            $line->height = $this->numericOrNull($dims['height'] ?? null);
            if ($line->image_url === null || trim((string) $line->image_url) === '') {
                $img = isset($product['image_url']) ? trim((string) $product['image_url']) : '';
                if ($img !== '') {
                    $line->image_url = $img;
                }
            }
            if (isset($product['id']) && is_string($product['id']) && trim($product['id']) !== '') {
                $line->shiphero_product_id = trim($product['id']);
            }
            $line->specs_cached_at = now();
            $line->save();
            $this->syncLineStatus($line);
            $enriched++;
        }

        return ['enriched' => $enriched];
    }

    /**
     * @param  mixed  $value
     */
    private function numericOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;

        return $n > 0 ? $n : null;
    }

    /**
     * @param  array<string, mixed>  $specs
     */
    public function updateLineSpecs(ClientAccountAsnLine $line, array $specs): ClientAccountAsnLine
    {
        if (array_key_exists('barcode', $specs)) {
            $line->barcode = trim((string) ($specs['barcode'] ?? '')) ?: null;
        }
        foreach (['weight', 'length', 'width', 'height'] as $k) {
            if (array_key_exists($k, $specs)) {
                $line->{$k} = $this->numericOrNull($specs[$k]);
            }
        }
        $line->save();

        return $line->fresh();
    }

    /**
     * @return array<string, int>
     */
    public function statusSummary(?int $clientAccountId = null): array
    {
        $query = ClientAccountAsn::query();
        if ($clientAccountId !== null && $clientAccountId > 0) {
            $query->where('client_account_id', $clientAccountId);
        }
        $rows = $query
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'pending' => (int) ($rows[ClientAccountAsn::STATUS_PENDING] ?? 0),
            'in_progress' => (int) ($rows[ClientAccountAsn::STATUS_IN_PROGRESS] ?? 0),
            'completed' => (int) ($rows[ClientAccountAsn::STATUS_COMPLETED] ?? 0),
            'non_compliant' => (int) ($rows[ClientAccountAsn::STATUS_NON_COMPLIANT] ?? 0),
        ];
    }

    public static function nonCompliantBillItemName(ClientAccountAsn $asn): string
    {
        $num = trim((string) $asn->asn_number);

        return 'Non-Compliant ASN'.($num !== '' ? ' #'.$num : '');
    }

    public static function receivingBillLineType(): string
    {
        return InvoiceLineCategory::RECEIVING;
    }
}
