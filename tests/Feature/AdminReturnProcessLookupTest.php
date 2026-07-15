<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReturnProcessLookupTest extends TestCase
{
    use RefreshDatabase;

    private function permission(string $key, string $module): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $key, 'module' => $module]
        );
    }

    private function staffUser(array $extraPermissionKeys = []): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $keys = array_merge(['returns.view', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Return Admin Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-ret-admin-'.$suffix,
        ]);
    }

    private function returnForAccount(ClientAccount $account, array $overrides = []): ClientAccountReturn
    {
        return ClientAccountReturn::query()->create(array_merge([
            'client_account_id' => $account->id,
            'rma_number' => '0001',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-123',
            'order_number' => 'ORD-100',
            'customer_name' => 'Jane Customer',
            'items_count' => 2,
        ], $overrides));
    }

    public function test_staff_can_lookup_pending_return_by_order_number(): void
    {
        $account = $this->account();
        $return = $this->returnForAccount($account, ['order_number' => 'ORD-555', 'rma_number' => '0100']);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-555')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $return->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.order_number', 'ORD-555');
    }

    public function test_staff_can_lookup_pending_return_by_rma_number(): void
    {
        $account = $this->account();
        $return = $this->returnForAccount($account, ['rma_number' => '0200', 'order_number' => 'ORD-200']);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/process-lookup?rma_number=RMA%20%230200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $return->id)
            ->assertJsonPath('data.0.rma_number', '0200');
    }

    public function test_account_filter_narrows_lookup(): void
    {
        $accountA = $this->account('a');
        $accountB = $this->account('b');
        $match = $this->returnForAccount($accountA, ['order_number' => 'ORD-SAME', 'rma_number' => '0301']);
        $this->returnForAccount($accountB, ['order_number' => 'ORD-SAME', 'rma_number' => '0302']);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-SAME&client_account_id='.$accountA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_lookup_excludes_non_pending_returns(): void
    {
        $account = $this->account();
        $this->returnForAccount($account, [
            'order_number' => 'ORD-DRAFT',
            'rma_number' => '0401',
            'status' => ClientAccountReturn::STATUS_DRAFT,
        ]);
        $this->returnForAccount($account, [
            'order_number' => 'ORD-RECEIVED',
            'rma_number' => '0402',
            'status' => ClientAccountReturn::STATUS_RECEIVED,
            'processed_at' => now(),
        ]);
        $this->returnForAccount($account, [
            'order_number' => 'ORD-COMPLETED',
            'rma_number' => '0403',
            'status' => ClientAccountReturn::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-DRAFT')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-RECEIVED')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-COMPLETED')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_portal_user_cannot_use_process_lookup(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->permission('returns.view', 'returns')->id);
        $this->returnForAccount($account);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/returns/process-lookup?order_number=ORD-100')
            ->assertForbidden();
    }

    public function test_lookup_requires_order_or_rma(): void
    {
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/process-lookup')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_number']);
    }
}
