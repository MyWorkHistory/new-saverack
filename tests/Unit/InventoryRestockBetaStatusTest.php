<?php

namespace Tests\Unit;

use App\Models\InventoryRestockBetaSnapshot;
use App\Services\InventoryRestockBetaService;
use App\Support\Inventory\RestockBetaCsvParser;
use ReflectionMethod;
use Tests\TestCase;

class InventoryRestockBetaStatusTest extends TestCase
{
    private function service(): InventoryRestockBetaService
    {
        return new InventoryRestockBetaService(new RestockBetaCsvParser());
    }

    private function invoke(object $target, string $method, array $args = [])
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($target, $args);
    }

    public function test_default_pending_statuses_for_import_rows(): void
    {
        $statuses = $this->invoke($this->service(), 'defaultPendingStatuses', [[
            ['sku' => 'ABC-123', 'name' => 'Widget'],
            ['sku' => 'XYZ-9', 'name' => 'Gadget'],
        ]]);

        $this->assertSame([
            'abc-123' => InventoryRestockBetaService::STATUS_PENDING,
            'xyz-9' => InventoryRestockBetaService::STATUS_PENDING,
        ], $statuses);
    }

    public function test_to_array_includes_all_statuses_and_excludes_complete_from_active_counts(): void
    {
        $snapshot = new InventoryRestockBetaSnapshot([
            'original_filename' => 'restock.csv',
            'row_count' => 3,
            'rows' => [
                ['sku' => 'P-1', 'name' => 'Pending', 'restock_needed' => 1],
                ['sku' => 'T-1', 'name' => 'Cart', 'restock_needed' => 2],
                ['sku' => 'C-1', 'name' => 'Done', 'restock_needed' => 3],
            ],
            'completed_skus' => ['c-1'],
            'sku_statuses' => [
                'p-1' => InventoryRestockBetaService::STATUS_PENDING,
                't-1' => InventoryRestockBetaService::STATUS_TRANSFER_CART,
                'c-1' => InventoryRestockBetaService::STATUS_COMPLETE,
            ],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $payload = $this->invoke($this->service(), 'toArray', [$snapshot]);

        $this->assertCount(3, $payload['rows']);
        $this->assertSame(2, $payload['active_row_count']);
        $this->assertSame(3, $payload['restock_needed_total']);
        $this->assertSame(InventoryRestockBetaService::STATUS_PENDING, $payload['rows'][0]['status']);
        $this->assertSame('Pending', $payload['rows'][0]['status_label']);
        $this->assertSame(InventoryRestockBetaService::STATUS_TRANSFER_CART, $payload['rows'][1]['status']);
        $this->assertSame('Transfer Cart', $payload['rows'][1]['status_label']);
        $this->assertSame(InventoryRestockBetaService::STATUS_COMPLETE, $payload['rows'][2]['status']);
        $this->assertSame('Complete', $payload['rows'][2]['status_label']);
    }

    public function test_legacy_completed_skus_map_to_complete_when_sku_statuses_missing(): void
    {
        $snapshot = new InventoryRestockBetaSnapshot([
            'original_filename' => 'legacy.csv',
            'row_count' => 2,
            'rows' => [
                ['sku' => 'KEEP-1', 'name' => 'Keep', 'restock_needed' => 2],
                ['sku' => 'DONE-1', 'name' => 'Done', 'restock_needed' => 5],
            ],
            'completed_skus' => ['done-1'],
            'sku_statuses' => null,
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $payload = $this->invoke($this->service(), 'toArray', [$snapshot]);

        $this->assertCount(2, $payload['rows']);
        $this->assertSame(1, $payload['active_row_count']);
        $this->assertSame(2, $payload['restock_needed_total']);
        $this->assertSame(InventoryRestockBetaService::STATUS_PENDING, $payload['rows'][0]['status']);
        $this->assertSame(InventoryRestockBetaService::STATUS_COMPLETE, $payload['rows'][1]['status']);
    }

    public function test_status_label_values(): void
    {
        $this->assertSame('Pending', InventoryRestockBetaService::statusLabel(
            InventoryRestockBetaService::STATUS_PENDING
        ));
        $this->assertSame('Transfer Cart', InventoryRestockBetaService::statusLabel(
            InventoryRestockBetaService::STATUS_TRANSFER_CART
        ));
        $this->assertSame('Complete', InventoryRestockBetaService::statusLabel(
            InventoryRestockBetaService::STATUS_COMPLETE
        ));
    }
}
