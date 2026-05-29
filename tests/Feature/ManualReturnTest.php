<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualReturnTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function account(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Manual Return Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-manual-ret-1',
        ]);
    }

    public function test_manual_draft_uses_manual_shiphero_id_sentinel(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/returns/draft', [
            'client_account_id' => $account->id,
            'manual' => true,
            'order_number' => 'EXT-ORDER-99',
            'customer_name' => 'Jane Recipient',
            'return_type' => 'direct',
        ]);

        $response->assertCreated()
            ->assertJsonPath('order_number', 'EXT-ORDER-99')
            ->assertJsonPath('customer_name', 'Jane Recipient')
            ->assertJsonPath('status', 'draft');

        $shipheroId = (string) $response->json('shiphero_order_id');
        $this->assertStringStartsWith('manual:', $shipheroId);
    }

    public function test_manual_return_submit_sets_pending_with_catalog_lines(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $draft = $this->postJson('/api/returns/draft', [
            'client_account_id' => $account->id,
            'manual' => true,
            'order_number' => 'EXT-100',
            'customer_name' => 'John Doe',
        ])->assertCreated();

        $returnId = (int) $draft->json('id');

        $submit = $this->putJson('/api/returns/'.$returnId.'/submit', [
            'return_type' => 'direct',
            'lines' => [
                [
                    'sku' => 'SKU-MAN-1',
                    'name' => 'Manual Widget',
                    'order_qty' => 0,
                    'return_qty' => 2,
                    'return_reason' => 'damaged',
                ],
            ],
        ]);

        $submit->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('items_count', 2);

        $return = ClientAccountReturn::query()->findOrFail($returnId);
        $this->assertSame('pending', $return->status);
        $line = $return->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(2, $line->return_qty);
        $this->assertSame(2, $line->order_qty);
    }
}
