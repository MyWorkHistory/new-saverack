<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\User;
use App\Services\ShopifyProductCsvService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShopifyProductCsvApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "Name,SKU,Barcode,Weight,Height,Width,Length\n";

    /** @var int */
    private $shopifyIdSeed = 1000;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0:ClientAccount,1:ClientAccountShopifyConnection}
     */
    private function seedConnection(string $token = ''): array
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'CSV Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'csv-co.myshopify.com',
            'admin_api_access_token' => $token,
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        return [$account, $connection];
    }

    private function seedVariant(
        ClientAccountShopifyConnection $connection,
        string $sku,
        array $attributes = []
    ): ShopifyProductVariant {
        $seed = $this->shopifyIdSeed++;
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => (string) $seed,
            'title' => 'Old Name',
            'status' => 'active',
            'crm_product_kind' => ShopifyProduct::KIND_STANDARD,
        ]);

        return ShopifyProductVariant::query()->create(array_merge([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => (string) ($seed * 10),
            'shopify_inventory_item_id' => (string) ($seed * 100),
            'title' => 'Default',
            'sku' => $sku,
        ], $attributes));
    }

    private function csvFile(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $contents);
    }

    /**
     * @param  list<string>  $required
     * @return array{rows: list<array{row:int, values:array<string, mixed>}>, errors: list<array<string, mixed>>}
     */
    private function parseRows(string $contents, array $required): array
    {
        $path = tempnam(sys_get_temp_dir(), 'shopify-csv');
        file_put_contents($path, $contents);

        try {
            return app(ShopifyProductCsvService::class)->parse($path, $required);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_requires_admin(): void
    {
        [$account] = $this->seedConnection('shpat_token');
        Sanctum::actingAs(User::factory()->create(['client_account_id' => null]));

        $this->post(
            '/api/shopify/inventory/import',
            ['client_account_id' => $account->id, 'file' => $this->csvFile(self::HEADER."Widget,W-1,,,,,\n")],
            ['Accept' => 'application/json']
        )->assertForbidden();
    }

    public function test_import_rejects_account_without_shopify_credentials(): void
    {
        $this->actingAsAdmin();
        [$account] = $this->seedConnection('');

        $this->post(
            '/api/shopify/inventory/import',
            ['client_account_id' => $account->id, 'file' => $this->csvFile(self::HEADER."Widget,W-1,,,,,\n")],
            ['Accept' => 'application/json']
        )->assertStatus(422)
            ->assertJsonValidationErrors('client_account_id');
    }

    public function test_import_requires_name_and_sku_columns(): void
    {
        $this->actingAsAdmin();
        [$account] = $this->seedConnection('shpat_token');

        $this->post(
            '/api/shopify/inventory/import',
            ['client_account_id' => $account->id, 'file' => $this->csvFile("Barcode,Weight\n111,2\n")],
            ['Accept' => 'application/json']
        )->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_import_creates_products_in_shopify_and_skips_rows_missing_name(): void
    {
        $this->actingAsAdmin();
        [$account, $connection] = $this->seedConnection('shpat_token');

        $created = [];
        $sync = Mockery::mock(ShopifyProductSyncService::class);
        $sync->shouldReceive('createProductWithVariant')
            ->twice()
            ->andReturnUsing(function ($conn, array $fields) use ($connection, &$created) {
                $created[] = $fields;

                return $this->seedVariant($connection, (string) $fields['sku']);
            });
        $this->app->instance(ShopifyProductSyncService::class, $sync);

        $csv = self::HEADER
            ."Blue Widget,BW-1,111,1.5,2,3,4\n"
            ."Red Widget,RW-1,,,,,\n"
            .",NO-NAME-1,,,,,\n";

        $this->post(
            '/api/shopify/inventory/import',
            ['client_account_id' => $account->id, 'file' => $this->csvFile($csv)],
            ['Accept' => 'application/json']
        )->assertStatus(202)
            ->assertJsonPath('queued', 2)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('errors.0.message', 'NAME is required.');

        $this->assertCount(2, $created);
        $this->assertSame('Blue Widget', $created[0]['name']);
        $this->assertSame(1.5, $created[0]['weight']);
        $this->assertSame(4.0, $created[0]['length']);
        // Blank cells never reach Shopify.
        $this->assertSame(['name', 'sku'], array_keys($created[1]));
    }

    public function test_import_updates_existing_sku_instead_of_creating(): void
    {
        $this->actingAsAdmin();
        [$account, $connection] = $this->seedConnection('shpat_token');
        $variant = $this->seedVariant($connection, 'BW-1', ['weight' => 5]);

        $sync = Mockery::mock(ShopifyProductSyncService::class);
        $sync->shouldReceive('createProductWithVariant')->never();
        $sync->shouldReceive('pushVariantToShopify')
            ->once()
            ->andReturnUsing(function (ShopifyProductVariant $pushed) {
                return $pushed;
            });
        $this->app->instance(ShopifyProductSyncService::class, $sync);

        $this->post(
            '/api/shopify/inventory/import',
            [
                'client_account_id' => $account->id,
                'file' => $this->csvFile(self::HEADER."Blue Widget,BW-1,,2.25,,,\n"),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(202)
            ->assertJsonPath('queued', 1);

        $variant->refresh();
        $this->assertSame(2.25, (float) $variant->weight);
        $this->assertSame('Blue Widget', $variant->product->title);
    }

    public function test_bulk_edit_requires_sku_column(): void
    {
        $this->actingAsAdmin();

        $this->post(
            '/api/shopify/inventory/bulk-edit',
            ['file' => $this->csvFile("Name,Barcode\nWidget,111\n")],
            ['Accept' => 'application/json']
        )->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_bulk_edit_only_updates_and_pushes_filled_columns(): void
    {
        $this->actingAsAdmin();
        [, $connection] = $this->seedConnection('shpat_token');
        $variant = $this->seedVariant($connection, 'SKU-1', [
            'barcode' => 'OLD-BARCODE',
            'weight' => 5,
            'weight_unit' => 'POUNDS',
            'length' => 9,
        ]);

        $pushed = null;
        $sync = Mockery::mock(ShopifyProductSyncService::class);
        $sync->shouldReceive('pushVariantToShopify')
            ->once()
            ->andReturnUsing(function (ShopifyProductVariant $target, array $fields) use (&$pushed) {
                $pushed = $fields;

                return $target;
            });
        $this->app->instance(ShopifyProductSyncService::class, $sync);

        $this->post(
            '/api/shopify/inventory/bulk-edit',
            ['file' => $this->csvFile(self::HEADER."New Name,SKU-1,,7.5,,,\n")],
            ['Accept' => 'application/json']
        )->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('skipped', 0);

        $this->assertIsArray($pushed);
        $this->assertSame(['product_title', 'weight', 'weight_unit'], array_keys($pushed));
        $this->assertSame('New Name', $pushed['product_title']);
        $this->assertSame(7.5, $pushed['weight']);

        $variant->refresh();
        $this->assertSame('New Name', $variant->product->title);
        $this->assertSame(7.5, (float) $variant->weight);
        // Blank cells keep their current value.
        $this->assertSame('OLD-BARCODE', $variant->barcode);
        $this->assertSame(9.0, (float) $variant->length);
    }

    public function test_bulk_edit_skips_rows_with_a_blank_sku(): void
    {
        $this->actingAsAdmin();

        $this->post(
            '/api/shopify/inventory/bulk-edit',
            ['file' => $this->csvFile(self::HEADER."Nameless,,111,,,,\n")],
            ['Accept' => 'application/json']
        )->assertStatus(202)
            ->assertJsonPath('queued', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('errors.0.message', 'SKU is required.');
    }

    public function test_bulk_edit_reports_unknown_sku(): void
    {
        $this->actingAsAdmin();
        $this->seedConnection('shpat_token');

        $parsed = $this->parseRows(self::HEADER."Ghost,MISSING-1,,,,,\n", ['sku']);
        $result = app(ShopifyProductCsvService::class)->bulkEdit($parsed['rows'], null);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['missing']);
        $this->assertSame('No product found with this SKU.', $result['errors'][0]['message']);
    }

    public function test_bulk_edit_scopes_sku_match_to_the_selected_account(): void
    {
        $this->actingAsAdmin();
        [, $connectionA] = $this->seedConnection('shpat_token');
        $accountB = ClientAccount::query()->create([
            'company_name' => 'Other Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connectionB = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $accountB->id,
            'shop_domain' => 'other-co.myshopify.com',
            'admin_api_access_token' => 'shpat_token',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        $variantA = $this->seedVariant($connectionA, 'SHARED-1', ['barcode' => 'A-BARCODE']);
        $variantB = $this->seedVariant($connectionB, 'SHARED-1', ['barcode' => 'B-BARCODE']);

        $sync = Mockery::mock(ShopifyProductSyncService::class);
        $sync->shouldReceive('pushVariantToShopify')
            ->once()
            ->andReturnUsing(function (ShopifyProductVariant $target) {
                return $target;
            });
        $this->app->instance(ShopifyProductSyncService::class, $sync);

        $parsed = $this->parseRows(self::HEADER.",SHARED-1,NEW-BARCODE,,,,\n", ['sku']);
        $result = app(ShopifyProductCsvService::class)->bulkEdit($parsed['rows'], (int) $accountB->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('A-BARCODE', $variantA->refresh()->barcode);
        $this->assertSame('NEW-BARCODE', $variantB->refresh()->barcode);
    }

    public function test_parse_rejects_non_numeric_measurements(): void
    {
        $this->actingAsAdmin();

        $this->post(
            '/api/shopify/inventory/bulk-edit',
            ['file' => $this->csvFile(self::HEADER."Widget,SKU-1,,heavy,,,\n")],
            ['Accept' => 'application/json']
        )->assertStatus(202)
            ->assertJsonPath('queued', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('errors.0.message', 'Weight must be a positive number.');
    }

    public function test_parse_accepts_aliased_headers(): void
    {
        $parsed = $this->parseRows(
            "Product Name,SKU Code,UPC,Weight (lb),Height (in),Width (in),Length (in)\n"
            ."Aliased Widget,AW-1,999,3,1,2,4\n",
            ['name', 'sku']
        );

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([
            'name' => 'Aliased Widget',
            'sku' => 'AW-1',
            'barcode' => '999',
            'weight' => 3.0,
            'height' => 1.0,
            'width' => 2.0,
            'length' => 4.0,
        ], $parsed['rows'][0]['values']);
    }
}
