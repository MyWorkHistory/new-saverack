<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\OrderDraft;
use App\Models\Permission;
use App\Models\User;
use App\Services\OrderDraftService;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderDraftApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ordersUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'orders.update'],
            ['label' => 'Update orders', 'module' => 'orders']
        );
    }

    private function ordersViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'orders.view'],
            ['label' => 'View orders', 'module' => 'orders']
        );
    }

    private function staffWithOrdersUpdate(): User
    {
        $user = User::factory()->create(['client_account_id' => null, 'name' => 'Staff Creator']);
        $user->permissions()->sync([
            $this->ordersUpdatePermission()->id,
            $this->ordersViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function staffWithOrdersView(): User
    {
        $user = User::factory()->create(['client_account_id' => null, 'name' => 'Staff Viewer']);
        $user->permissions()->sync([$this->ordersViewPermission()->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeDraftForAccount(ClientAccount $account, string $orderNumber = 'LIST-DRAFT-1'): OrderDraft
    {
        return OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => $orderNumber,
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id, $orderNumber)['shipping_address'],
            'line_items' => [],
            'tags' => [],
            'created_by_user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validDraftPayload(int $clientAccountId, string $orderNumber = 'CRM-DRAFT-1001'): array
    {
        return [
            'client_account_id' => $clientAccountId,
            'order_number' => $orderNumber,
            'shipping_address' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
                'country' => 'US',
                'email' => 'jane@example.test',
                'phone' => '5555550100',
            ],
        ];
    }

    private function makeAccount(string $shipheroId = 'sh-draft-1'): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Draft Client',
            'shiphero_customer_account_id' => $shipheroId,
        ]);
    }

    public function test_guest_cannot_create_draft(): void
    {
        $this->postJson('/api/order-drafts', [])->assertUnauthorized();
    }

    public function test_staff_without_orders_update_cannot_create_draft(): void
    {
        Sanctum::actingAs(User::factory()->create(['client_account_id' => null]));
        $account = $this->makeAccount('sh-noperm-draft');

        $this->postJson('/api/order-drafts', $this->validDraftPayload($account->id))
            ->assertForbidden();
    }

    public function test_create_draft_validation_errors(): void
    {
        $this->staffWithOrdersUpdate();

        $this->postJson('/api/order-drafts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_account_id', 'order_number', 'shipping_address']);
    }

    public function test_staff_can_create_draft(): void
    {
        $this->staffWithOrdersUpdate();
        $account = $this->makeAccount();

        $response = $this->postJson('/api/order-drafts', $this->validDraftPayload($account->id))
            ->assertCreated()
            ->assertJsonPath('order_number', 'CRM-DRAFT-1001')
            ->assertJsonPath('client_account_id', $account->id);

        $draftId = (int) $response->json('draft_id');
        $this->assertDatabaseHas('order_drafts', [
            'id' => $draftId,
            'client_account_id' => $account->id,
            'order_number' => 'CRM-DRAFT-1001',
            'status' => OrderDraft::STATUS_DRAFT,
        ]);
    }

    public function test_portal_user_can_create_draft_for_own_account(): void
    {
        $account = $this->makeAccount('sh-portal-draft');
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'name' => 'Portal Creator',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/order-drafts', $this->validDraftPayload($account->id, 'PORTAL-DRAFT-1'))
            ->assertCreated()
            ->assertJsonPath('client_account_id', $account->id);
    }

    public function test_portal_user_cannot_create_draft_for_other_account(): void
    {
        $own = $this->makeAccount('sh-portal-own');
        $other = $this->makeAccount('sh-portal-other');
        $user = User::factory()->create(['client_account_id' => $own->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/order-drafts', $this->validDraftPayload($other->id))
            ->assertForbidden();
    }

    public function test_get_draft_order_detail_shape(): void
    {
        $this->staffWithOrdersUpdate();
        $account = $this->makeAccount();
        $draft = OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'DRAFT-SHOW-1',
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id)['shipping_address'],
            'line_items' => [],
            'tags' => [],
            'created_by_user_id' => User::factory()->create()->id,
        ]);

        $routeId = app(OrderDraftService::class)->encodeRouteId((int) $draft->id);

        $this->getJson('/api/orders/'.urlencode($routeId).'?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('is_draft', true)
            ->assertJsonPath('order.is_draft', true)
            ->assertJsonPath('order.order_number', 'DRAFT-SHOW-1')
            ->assertJsonPath('order.status', 'draft');
    }

    public function test_draft_line_item_mutation_persists(): void
    {
        $this->staffWithOrdersUpdate();
        $account = $this->makeAccount();
        $draft = OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'DRAFT-LINE-1',
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id)['shipping_address'],
            'line_items' => [],
            'tags' => [],
            'created_by_user_id' => User::factory()->create()->id,
        ]);
        $routeId = app(OrderDraftService::class)->encodeRouteId((int) $draft->id);

        $this->postJson('/api/orders/'.urlencode($routeId).'/line-items', [
            'client_account_id' => $account->id,
            'line_items' => [
                ['sku' => 'SKU-DRAFT-1', 'quantity' => 2, 'product_name' => 'Draft Widget'],
            ],
        ])->assertOk();

        $draft->refresh();
        $this->assertCount(1, $draft->line_items ?? []);
        $this->assertSame('SKU-DRAFT-1', $draft->line_items[0]['sku'] ?? null);
    }

    public function test_ready_to_ship_rejects_missing_shipping_method(): void
    {
        $this->staffWithOrdersUpdate();
        $account = $this->makeAccount();
        $draft = OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'DRAFT-RTS-FAIL',
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id)['shipping_address'],
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Select',
            'line_items' => [
                ['id' => 'draft-line:1', 'sku' => 'SKU-1', 'quantity' => 1, 'quantity_pending_fulfillment' => 1],
            ],
            'tags' => [],
            'created_by_user_id' => User::factory()->create()->id,
        ]);

        $this->postJson('/api/order-drafts/'.$draft->id.'/ready-to-ship')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping_method']);
    }

    public function test_ready_to_ship_submits_to_shiphero_and_records_history(): void
    {
        $user = $this->staffWithOrdersUpdate();
        $account = $this->makeAccount('sh-submit-draft');
        $draft = OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'DRAFT-RTS-OK',
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id)['shipping_address'],
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
            'line_items' => [
                [
                    'id' => 'draft-line:1',
                    'sku' => 'SKU-READY',
                    'product_name' => 'Ready Widget',
                    'quantity' => 1,
                    'quantity_pending_fulfillment' => 1,
                    'price' => 0,
                ],
            ],
            'tags' => [],
            'created_by_user_id' => $user->id,
        ]);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('createOrder')
            ->once()
            ->with('sh-submit-draft', Mockery::on(function (array $payload): bool {
                return $payload['order_number'] === 'DRAFT-RTS-OK'
                    && $payload['shop_name'] === 'Draft Client'
                    && count($payload['line_items']) === 1
                    && $payload['line_items'][0]['sku'] === 'SKU-READY';
            }))
            ->andReturn([
                'shiphero_order_id' => 'order-submitted-42',
                'order_number' => 'DRAFT-RTS-OK',
            ]);
        $mock->shouldReceive('updateOrderShippingLines')
            ->once()
            ->with('order-submitted-42', 'sh-submit-draft', 'ups', 'Ground');
        $mock->shouldReceive('addOrderHistoryEntry')
            ->once()
            ->with(
                'order-submitted-42',
                'sh-submit-draft',
                'Order created by Staff Creator via Save Rack.',
                'Save Rack CRM'
            );
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->postJson('/api/order-drafts/'.$draft->id.'/ready-to-ship')
            ->assertOk()
            ->assertJsonPath('shiphero_order_id', 'order-submitted-42')
            ->assertJsonPath('client_account_id', $account->id);

        $draft->refresh();
        $this->assertSame(OrderDraft::STATUS_SUBMITTED, $draft->status);
        $this->assertSame('order-submitted-42', $draft->shiphero_order_id);
    }

    public function test_mark_fulfilled_unavailable_for_draft(): void
    {
        $this->staffWithOrdersUpdate();
        $account = $this->makeAccount();
        $draft = OrderDraft::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'DRAFT-BLOCK-1',
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->validDraftPayload($account->id)['shipping_address'],
            'line_items' => [],
            'tags' => [],
            'created_by_user_id' => User::factory()->create()->id,
        ]);
        $routeId = app(OrderDraftService::class)->encodeRouteId((int) $draft->id);

        $this->postJson('/api/orders/'.urlencode($routeId).'/mark-fulfilled', [
            'client_account_id' => $account->id,
        ])
            ->assertStatus(422);
    }

    public function test_guest_cannot_list_drafts(): void
    {
        $this->getJson('/api/order-drafts')->assertUnauthorized();
    }

    public function test_staff_can_list_drafts(): void
    {
        $this->staffWithOrdersView();
        $account = $this->makeAccount('sh-list-1');
        $draft = $this->makeDraftForAccount($account, 'LIST-DRAFT-1');

        $this->getJson('/api/order-drafts')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', 'LIST-DRAFT-1')
            ->assertJsonPath('data.0.id', $draft->id)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'draft_route_id',
                    'order_number',
                    'client_account_id',
                    'client_account_company_name',
                    'recipient_name',
                    'line_items_count',
                    'created_at',
                ]],
            ]);
    }

    public function test_staff_can_filter_drafts_by_account(): void
    {
        $this->staffWithOrdersView();
        $accountA = $this->makeAccount('sh-list-a');
        $accountB = $this->makeAccount('sh-list-b');
        $this->makeDraftForAccount($accountA, 'LIST-A-1');
        $this->makeDraftForAccount($accountB, 'LIST-B-1');

        $this->getJson('/api/order-drafts?client_account_id='.$accountA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'LIST-A-1');
    }

    public function test_portal_user_lists_only_own_account_drafts(): void
    {
        $own = $this->makeAccount('sh-portal-list-own');
        $other = $this->makeAccount('sh-portal-list-other');
        $this->makeDraftForAccount($own, 'PORTAL-LIST-OWN');
        $this->makeDraftForAccount($other, 'PORTAL-LIST-OTHER');

        $user = User::factory()->create(['client_account_id' => $own->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/order-drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'PORTAL-LIST-OWN');
    }
}
