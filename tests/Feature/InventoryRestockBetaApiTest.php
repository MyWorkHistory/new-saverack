<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\InventoryRestockBetaSnapshot;
use App\Models\Permission;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Services\InventoryRestockBetaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryRestockBetaApiTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithInventoryView(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($permission->id);

        return $user;
    }

    private function sampleCsv(): string
    {
        return <<<'CSV'
SKU,Name,On hand,Allocated,Replenishment level,Available in pickable bins,Qty from Non-Pickable bins,Items to replenish,Top 3 Non-Pickable bins
ABC-123,Widget Alpha,100,10,50,2,523,42,"test 3 (QTY: 523), test 2 (QTY: 42)"
CSV;
    }

    public function test_post_import_returns_rows_immediately_with_completed_enrichment(): void
    {
        Config::set('services.shiphero.restock_dispatch_mode', 'after_response');
        Sanctum::actingAs($this->staffWithInventoryView());

        $account = ClientAccount::query()->create([
            'company_name' => 'Import Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-import-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-import-1',
            'sku' => 'ABC-123',
            'sku_search' => 'abc-123',
            'name' => 'Widget Alpha',
            'name_search' => 'widget alpha',
            'image_url' => 'https://cdn.example.com/widget.jpg',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 100,
            'allocated' => 10,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $file = UploadedFile::fake()->createWithContent('restock.csv', $this->sampleCsv());

        $response = $this->postJson('/api/inventory/restock-beta/import', [
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('row_count', 1);
        $response->assertJsonPath('active_row_count', 1);
        $response->assertJsonPath('restock_needed_total', 42);
        $response->assertJsonPath('original_filename', 'restock.csv');
        $response->assertJsonPath('rows.0.sku', 'ABC-123');
        $response->assertJsonPath('rows.0.restock_needed', 42);
        $response->assertJsonPath('rows.0.account_name', 'Import Co');
        $response->assertJsonPath('rows.0.image_url', 'https://cdn.example.com/widget.jpg');
        $response->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);

        $this->assertDatabaseCount('inventory_restock_beta_snapshots', 1);
    }

    public function test_post_import_notifies_restock_slack_channel(): void
    {
        Config::set('services.shiphero.restock_dispatch_mode', 'after_response');
        Config::set('billing.slack.webhook_url', 'https://hooks.slack.com/services/T/B/x');
        Config::set('billing.slack.bot_token', null);
        Config::set('billing.slack.restock_channel', '#restock');
        Config::set('crm.frontend_url', 'https://app.saverack.com');
        Config::set('billing.slack.public_asset_base_url', 'https://app.saverack.com');

        Sanctum::actingAs($this->staffWithInventoryView());

        \Illuminate\Support\Facades\Http::fake([
            'hooks.slack.com/*' => \Illuminate\Support\Facades\Http::response('ok', 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('restock.csv', $this->sampleCsv());

        $this->postJson('/api/inventory/restock-beta/import', [
            'file' => $file,
        ])->assertCreated();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hooks.slack.com')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['username'] ?? '') === 'Restock Needed'
                && ($payload['channel'] ?? '') === '#restock'
                && str_contains((string) ($payload['text'] ?? ''), '1 SKUs Need Restocking')
                && str_contains((string) ($payload['text'] ?? ''), '10 Allocated Orders')
                && str_contains((string) ($payload['text'] ?? ''), 'View Restocks');
        });
    }

    public function test_get_inline_enriches_pending_snapshot(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $account = ClientAccount::query()->create([
            'company_name' => 'Pending Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-pending-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-pending-1',
            'sku' => 'SAVE-1',
            'sku_search' => 'save-1',
            'name' => 'Saved Row',
            'name_search' => 'saved row',
            'image_url' => 'https://cdn.example.com/saved.jpg',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH2',
            'warehouse_active' => true,
            'on_hand' => 5,
            'allocated' => 1,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'saved.csv',
            'row_count' => 1,
            'rows' => [
                [
                    'sku' => 'SAVE-1',
                    'name' => 'Saved Row',
                    'on_hand' => 5,
                    'allocated' => 1,
                    'pickable_qty' => 0,
                    'backstock_qty' => 10,
                    'restock_needed' => 3,
                    'backstock_locations' => 'bin-a',
                ],
            ],
            'completed_skus' => [],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_PENDING,
            'uploaded_at' => now(),
        ]);

        $response = $this->getJson('/api/inventory/restock-beta');

        $response->assertOk();
        $response->assertJsonPath('rows.0.account_name', 'Pending Co');
        $response->assertJsonPath('rows.0.image_url', 'https://cdn.example.com/saved.jpg');
        $response->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);
    }

    public function test_get_returns_latest_snapshot(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'saved.csv',
            'row_count' => 1,
            'rows' => [
                [
                    'sku' => 'SAVE-1',
                    'name' => 'Saved Row',
                    'on_hand' => 5,
                    'allocated' => 1,
                    'pickable_qty' => 0,
                    'backstock_qty' => 10,
                    'restock_needed' => 3,
                    'backstock_locations' => 'bin-a',
                ],
            ],
            'completed_skus' => [],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $response = $this->getJson('/api/inventory/restock-beta');

        $response->assertOk();
        $response->assertJsonPath('original_filename', 'saved.csv');
        $response->assertJsonPath('row_count', 1);
        $response->assertJsonPath('active_row_count', 1);
        $response->assertJsonPath('restock_needed_total', 3);
        $response->assertJsonPath('rows.0.sku', 'SAVE-1');
        $response->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);
    }

    public function test_get_without_snapshot_returns_empty_payload(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $response = $this->getJson('/api/inventory/restock-beta');

        $response->assertOk();
        $response->assertJsonPath('row_count', 0);
        $response->assertJsonPath('active_row_count', 0);
        $response->assertJsonPath('restock_needed_total', 0);
        $response->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);
        $response->assertJsonPath('rows', []);
    }

    public function test_invalid_csv_returns_422(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $file = UploadedFile::fake()->createWithContent('bad.csv', "On hand,Allocated\n1,2\n");

        $response = $this->postJson('/api/inventory/restock-beta/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Missing required CSV columns: SKU and Name.');
    }

    public function test_import_replaces_previous_snapshot(): void
    {
        Config::set('services.shiphero.restock_dispatch_mode', 'sync');
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'old.csv',
            'row_count' => 1,
            'rows' => [['sku' => 'OLD-1', 'name' => 'Old']],
            'completed_skus' => ['OLD-1'],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now()->subDay(),
        ]);

        $file = UploadedFile::fake()->createWithContent('new.csv', $this->sampleCsv());

        $this->postJson('/api/inventory/restock-beta/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('rows.0.sku', 'ABC-123')
            ->assertJsonPath('active_row_count', 1)
            ->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);

        $this->assertDatabaseCount('inventory_restock_beta_snapshots', 1);
        $this->assertDatabaseHas('inventory_restock_beta_snapshots', [
            'original_filename' => 'new.csv',
        ]);
    }

    public function test_post_import_defaults_rows_to_pending_status(): void
    {
        Config::set('services.shiphero.restock_dispatch_mode', 'after_response');
        Sanctum::actingAs($this->staffWithInventoryView());

        $file = UploadedFile::fake()->createWithContent('restock.csv', $this->sampleCsv());

        $this->postJson('/api/inventory/restock-beta/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('rows.0.sku', 'ABC-123')
            ->assertJsonPath('rows.0.status', InventoryRestockBetaService::STATUS_PENDING)
            ->assertJsonPath('rows.0.status_label', 'Pending');
    }

    public function test_post_complete_keeps_sku_on_list_with_complete_status(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'saved.csv',
            'row_count' => 2,
            'rows' => [
                [
                    'sku' => 'KEEP-1',
                    'name' => 'Keep Row',
                    'restock_needed' => 2,
                ],
                [
                    'sku' => 'DONE-1',
                    'name' => 'Done Row',
                    'restock_needed' => 5,
                ],
            ],
            'completed_skus' => [],
            'sku_statuses' => [
                'keep-1' => InventoryRestockBetaService::STATUS_PENDING,
                'done-1' => InventoryRestockBetaService::STATUS_PENDING,
            ],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $this->postJson('/api/inventory/restock-beta/complete', ['sku' => 'DONE-1'])
            ->assertOk()
            ->assertJsonPath('active_row_count', 1)
            ->assertJsonPath('restock_needed_total', 2)
            ->assertJsonCount(2, 'rows');

        $response = $this->getJson('/api/inventory/restock-beta');
        $response->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('active_row_count', 1);

        $rows = collect($response->json('rows'));
        $done = $rows->firstWhere('sku', 'DONE-1');
        $keep = $rows->firstWhere('sku', 'KEEP-1');

        $this->assertNotNull($done);
        $this->assertNotNull($keep);
        $this->assertSame(InventoryRestockBetaService::STATUS_COMPLETE, $done['status']);
        $this->assertSame('Complete', $done['status_label']);
        $this->assertSame(InventoryRestockBetaService::STATUS_PENDING, $keep['status']);
    }

    public function test_post_status_supports_transfer_cart_and_complete_transitions(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'saved.csv',
            'row_count' => 1,
            'rows' => [
                [
                    'sku' => 'CART-1',
                    'name' => 'Cart Row',
                    'restock_needed' => 4,
                ],
            ],
            'completed_skus' => [],
            'sku_statuses' => [
                'cart-1' => InventoryRestockBetaService::STATUS_PENDING,
            ],
            'enrichment_status' => InventoryRestockBetaService::ENRICHMENT_COMPLETED,
            'uploaded_at' => now(),
        ]);

        $this->postJson('/api/inventory/restock-beta/status', [
            'sku' => 'CART-1',
            'status' => InventoryRestockBetaService::STATUS_TRANSFER_CART,
        ])
            ->assertOk()
            ->assertJsonPath('rows.0.status', InventoryRestockBetaService::STATUS_TRANSFER_CART)
            ->assertJsonPath('rows.0.status_label', 'Transfer')
            ->assertJsonPath('active_row_count', 1)
            ->assertJsonPath('restock_needed_total', 4);

        $this->postJson('/api/inventory/restock-beta/status', [
            'sku' => 'CART-1',
            'status' => InventoryRestockBetaService::STATUS_COMPLETE,
        ])
            ->assertOk()
            ->assertJsonPath('rows.0.status', InventoryRestockBetaService::STATUS_COMPLETE)
            ->assertJsonPath('rows.0.status_label', 'Complete')
            ->assertJsonPath('active_row_count', 0)
            ->assertJsonPath('restock_needed_total', 0)
            ->assertJsonCount(1, 'rows');
    }

    public function test_get_includes_complete_and_transfer_cart_rows(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'saved.csv',
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

        $this->getJson('/api/inventory/restock-beta')
            ->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('active_row_count', 2)
            ->assertJsonPath('restock_needed_total', 3)
            ->assertJsonPath('rows.0.status', InventoryRestockBetaService::STATUS_PENDING)
            ->assertJsonPath('rows.1.status', InventoryRestockBetaService::STATUS_TRANSFER_CART)
            ->assertJsonPath('rows.2.status', InventoryRestockBetaService::STATUS_COMPLETE);
    }

    public function test_import_enriches_account_from_inventory_index_when_sync(): void
    {
        Config::set('services.shiphero.restock_dispatch_mode', 'sync');
        Sanctum::actingAs($this->staffWithInventoryView());

        $account = ClientAccount::query()->create([
            'company_name' => 'Acme Shoes',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-acme-1',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => 'sh-acme-1',
            'sku' => 'ABC-123',
            'sku_search' => 'abc-123',
            'name' => 'Widget Alpha',
            'name_search' => 'widget alpha',
            'image_url' => 'https://cdn.example.com/widget.jpg',
            'product_active' => true,
            'kit' => false,
            'kit_build' => false,
            'warehouse_id' => 'WH1',
            'warehouse_active' => true,
            'on_hand' => 100,
            'allocated' => 10,
            'backorder' => 0,
            'synced_at' => now(),
        ]);

        $file = UploadedFile::fake()->createWithContent('restock.csv', $this->sampleCsv());

        $this->postJson('/api/inventory/restock-beta/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('rows.0.client_account_id', $account->id)
            ->assertJsonPath('rows.0.account_name', 'Acme Shoes')
            ->assertJsonPath('rows.0.image_url', 'https://cdn.example.com/widget.jpg')
            ->assertJsonPath('rows.0.warehouse_id', 'WH1')
            ->assertJsonPath('enrichment_status', InventoryRestockBetaService::ENRICHMENT_COMPLETED);
    }
}
