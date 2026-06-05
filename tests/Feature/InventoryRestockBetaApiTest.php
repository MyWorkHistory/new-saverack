<?php

namespace Tests\Feature;

use App\Models\InventoryRestockBetaSnapshot;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_post_import_returns_rows_and_persists_snapshot(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $file = UploadedFile::fake()->createWithContent('restock.csv', $this->sampleCsv());

        $response = $this->postJson('/api/inventory/restock-beta/import', [
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('row_count', 1);
        $response->assertJsonPath('original_filename', 'restock.csv');
        $response->assertJsonPath('rows.0.sku', 'ABC-123');
        $response->assertJsonPath('rows.0.restock_needed', 42);

        $this->assertDatabaseCount('inventory_restock_beta_snapshots', 1);
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
            'uploaded_at' => now(),
        ]);

        $response = $this->getJson('/api/inventory/restock-beta');

        $response->assertOk();
        $response->assertJsonPath('original_filename', 'saved.csv');
        $response->assertJsonPath('row_count', 1);
        $response->assertJsonPath('rows.0.sku', 'SAVE-1');
    }

    public function test_get_without_snapshot_returns_empty_payload(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $response = $this->getJson('/api/inventory/restock-beta');

        $response->assertOk();
        $response->assertJsonPath('row_count', 0);
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
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockBetaSnapshot::query()->create([
            'original_filename' => 'old.csv',
            'row_count' => 1,
            'rows' => [['sku' => 'OLD-1', 'name' => 'Old']],
            'uploaded_at' => now()->subDay(),
        ]);

        $file = UploadedFile::fake()->createWithContent('new.csv', $this->sampleCsv());

        $this->postJson('/api/inventory/restock-beta/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('rows.0.sku', 'ABC-123');

        $this->assertDatabaseCount('inventory_restock_beta_snapshots', 1);
        $this->assertDatabaseHas('inventory_restock_beta_snapshots', [
            'original_filename' => 'new.csv',
        ]);
    }
}
