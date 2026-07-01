<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCreateApiTest extends TestCase
{
    use RefreshDatabase;

    private function ordersUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'orders.update'],
            ['label' => 'Update orders', 'module' => 'orders']
        );
    }

    private function staffWithOrdersUpdate(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->sync([$this->ordersUpdatePermission()->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Legacy immediate-create payload (UI now uses POST /api/order-drafts).
     *
     * @return array<string, mixed>
     */
    private function legacyImmediatePayload(int $clientAccountId): array
    {
        return [
            'client_account_id' => $clientAccountId,
            'order_number' => 'CRM-TEST-1001',
            'shop_name' => 'Test Shop',
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
            'line_items' => [
                [
                    'sku' => 'SKU-001',
                    'quantity' => 2,
                    'price' => 19.99,
                ],
            ],
        ];
    }

    public function test_guest_cannot_create_order(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized();
    }

    public function test_staff_without_orders_update_cannot_create(): void
    {
        Sanctum::actingAs(User::factory()->create(['client_account_id' => null]));

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'No Perm Client',
            'shiphero_customer_account_id' => 'sh-noperm-1',
        ]);

        $this->postJson('/api/orders', $this->legacyImmediatePayload($account->id))
            ->assertForbidden();
    }

    public function test_legacy_create_order_validation_errors(): void
    {
        $this->staffWithOrdersUpdate();

        $this->postJson('/api/orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_account_id', 'order_number', 'shop_name', 'shipping_address', 'line_items']);
    }
}
