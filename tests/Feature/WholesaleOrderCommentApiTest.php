<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderComment;
use App\Models\WholesaleOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesaleOrderCommentApiTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        foreach (['orders.view', 'orders.update'] as $key) {
            $perm = \App\Models\Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => 'orders']
            );
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Comment Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
    }

    public function test_staff_author_can_update_and_delete_comment(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'CMT-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
        ]);

        $comment = WholesaleOrderComment::query()->create([
            'wholesale_order_id' => $order->id,
            'user_id' => $staff->id,
            'body' => 'Original note',
        ]);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id.'/comments/'.$comment->id, [
            'body' => 'Updated note',
        ])
            ->assertOk()
            ->assertJsonPath('body', 'Updated note')
            ->assertJsonPath('updated_at', fn ($v) => $v !== null);

        $this->deleteJson('/api/admin/wholesale-orders/'.$order->id.'/comments/'.$comment->id)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('wholesale_order_comments', ['id' => $comment->id]);
    }

    public function test_other_staff_cannot_modify_comment(): void
    {
        $account = $this->account();
        $author = $this->staffUser();
        $other = $this->staffUser();

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'CMT-2',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
        ]);

        $comment = WholesaleOrderComment::query()->create([
            'wholesale_order_id' => $order->id,
            'user_id' => $author->id,
            'body' => 'Private note',
        ]);

        Sanctum::actingAs($other);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id.'/comments/'.$comment->id, [
            'body' => 'Hacked',
        ])->assertForbidden();

        $this->deleteJson('/api/admin/wholesale-orders/'.$order->id.'/comments/'.$comment->id)
            ->assertForbidden();
    }
}
