<?php

namespace Tests\Feature;

use App\Models\LocationLabel;
use App\Models\Permission;
use App\Models\User;
use App\Services\LocationLabelSqlImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationLabelApiTest extends TestCase
{
    use RefreshDatabase;

    private function grant(User $user, string ...$keys): void
    {
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'label' => $key,
                    'module' => explode('.', $key)[0] ?? 'inventory',
                ]
            );
            $user->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    private function staffUser(array $keys = ['inventory_location_labels.view']): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $this->grant($user, ...$keys);

        return $user;
    }

    private function seedLabels(): void
    {
        LocationLabel::query()->create([
            'location' => 'A-01-001',
            'type' => 'Aisle 1 Slot 1',
            'label' => null,
            'is_deleted' => false,
        ]);
        LocationLabel::query()->create([
            'location' => 'B-02-010',
            'type' => 'Aisle 2 Slot 10',
            'label' => null,
            'is_deleted' => false,
        ]);
        LocationLabel::query()->create([
            'location' => 'GONE-1',
            'type' => 'Deleted Bin',
            'label' => null,
            'is_deleted' => true,
        ]);
    }

    public function test_guest_cannot_list_location_labels(): void
    {
        $this->getJson('/api/inventory/location-labels')->assertUnauthorized();
    }

    public function test_portal_user_cannot_list_location_labels(): void
    {
        $user = User::factory()->create(['client_account_id' => 1]);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/location-labels')->assertForbidden();
    }

    public function test_user_without_permission_cannot_list(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/location-labels')->assertForbidden();
    }

    public function test_list_excludes_deleted_and_supports_search_pagination_sort(): void
    {
        $this->seedLabels();
        $user = $this->staffUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/location-labels?per_page=1&sort_by=barcode&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('locations.0.barcode', 'A-01-001')
            ->assertJsonMissing(['barcode' => 'GONE-1']);

        $this->getJson('/api/inventory/location-labels?q=Slot%2010')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('locations.0.barcode', 'B-02-010');

        $this->getJson('/api/inventory/location-labels?q=A-01')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('locations.0.display_name', 'Aisle 1 Slot 1');
    }

    public function test_create_update_delete_and_duplicate_barcode(): void
    {
        $user = $this->staffUser(
            'inventory_location_labels.view',
            'inventory_location_labels.create',
            'inventory_location_labels.update',
            'inventory_location_labels.delete'
        );
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/inventory/location-labels', [
            'barcode' => 'C-03-001',
            'display_name' => 'Cold Storage 1',
        ])->assertCreated()
            ->assertJsonPath('location.barcode', 'C-03-001')
            ->assertJsonPath('location.display_name', 'Cold Storage 1');

        $id = (int) $create->json('location.id');

        $this->postJson('/api/inventory/location-labels', [
            'barcode' => 'C-03-001',
            'display_name' => 'Duplicate',
        ])->assertStatus(422);

        $this->patchJson('/api/inventory/location-labels/'.$id, [
            'barcode' => 'C-03-001',
            'display_name' => 'Cold Storage Updated',
        ])->assertOk()
            ->assertJsonPath('location.display_name', 'Cold Storage Updated');

        $this->deleteJson('/api/inventory/location-labels/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseHas('beta_locations', [
            'id' => $id,
            'is_deleted' => 1,
        ]);

        $this->getJson('/api/inventory/location-labels')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_bulk_delete(): void
    {
        $this->seedLabels();
        $user = $this->staffUser(
            'inventory_location_labels.view',
            'inventory_location_labels.delete'
        );
        Sanctum::actingAs($user);

        $ids = LocationLabel::query()->active()->pluck('id')->all();

        $this->postJson('/api/inventory/location-labels/bulk-delete', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('count', 2);

        $this->assertSame(0, LocationLabel::query()->active()->count());
    }

    public function test_csv_import_header_duplicates_and_errors(): void
    {
        LocationLabel::query()->create([
            'location' => 'EXISTING',
            'type' => 'Already Here',
            'is_deleted' => false,
        ]);

        $user = $this->staffUser(
            'inventory_location_labels.view',
            'inventory_location_labels.create'
        );
        Sanctum::actingAs($user);

        $csv = "Barcode,Display Name\nEXISTING,Skip Me\nNEW-1,New One\n,Missing Barcode\nONLY-BARCODE,\nNEW-2,Second New\n";
        $file = UploadedFile::fake()->createWithContent('locations.csv', $csv);

        $this->post('/api/inventory/location-labels/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 3);

        $this->assertDatabaseHas('beta_locations', [
            'location' => 'NEW-1',
            'type' => 'New One',
            'is_deleted' => 0,
        ]);
        $this->assertDatabaseHas('beta_locations', [
            'location' => 'NEW-2',
            'type' => 'Second New',
            'is_deleted' => 0,
        ]);
    }

    public function test_print_large_and_small_pdf(): void
    {
        $this->seedLabels();
        $user = $this->staffUser();
        Sanctum::actingAs($user);

        $id = (int) LocationLabel::query()->active()->value('id');

        $large = $this->postJson('/api/inventory/location-labels/print', [
            'ids' => [$id],
            'label_type' => 'large',
        ]);
        $large->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $large->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $large->getContent());

        $small = $this->postJson('/api/inventory/location-labels/print', [
            'ids' => [$id],
            'label_type' => 'small',
        ]);
        $small->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $small->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $small->getContent());
    }

    public function test_sql_importer_loads_legacy_dump_fixture(): void
    {
        $path = storage_path('framework/testing/beta_locations_fixture.sql');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, <<<'SQL'
INSERT INTO `beta_locations` (`id`, `location`, `type`, `label`, `is_deleted`, `created_at`, `updated_at`) VALUES
(101, 'FIX-001', 'Fixture One', NULL, 0, '2025-12-18 13:37:18', '2025-12-18 13:37:18'),
(102, 'FIX-002', 'Fixture Two', NULL, 1, '2025-12-18 13:37:18', '2025-12-18 13:37:18');
SQL);

        $result = app(LocationLabelSqlImporter::class)->importFromDump($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, DB::table('beta_locations')->count());
        $this->assertSame(1, LocationLabel::query()->active()->count());
        $this->assertDatabaseHas('beta_locations', [
            'id' => 101,
            'location' => 'FIX-001',
            'type' => 'Fixture One',
            'is_deleted' => 0,
        ]);
        $this->assertDatabaseHas('beta_locations', [
            'id' => 102,
            'is_deleted' => 1,
        ]);

        @unlink($path);
    }

    public function test_legacy_inventory_view_permission_grants_access(): void
    {
        $this->seedLabels();
        $user = $this->staffUser('inventory.view');
        Sanctum::actingAs($user);

        // hasPermission expands legacy inventory.view → inventory_location_labels.view
        $this->getJson('/api/inventory/location-labels')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }
}
