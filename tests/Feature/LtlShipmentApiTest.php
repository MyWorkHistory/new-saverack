<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\LtlShipment;
use App\Models\Permission;
use App\Models\User;
use App\Services\SlackDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LtlShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function permission(string $key): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $key, 'module' => explode('.', $key)[0]]
        );
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'LTL Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-ltl-'.$suffix,
        ]);
    }

    private function staffUser(array $keys = []): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $keys = array_merge([
            'receiving_ltl.view',
            'receiving_ltl.create',
            'receiving_ltl.update',
            'receiving_ltl.delete',
            'clients.view',
        ], $keys);
        foreach ($keys as $key) {
            $user->permissions()->syncWithoutDetaching([$this->permission($key)->id]);
        }

        return $user;
    }

    private function portalUser(ClientAccount $account): User
    {
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->permission('inventory.view')->id);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(int $accountId): array
    {
        return [
            'client_account_id' => $accountId,
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Pickup Warehouse',
            'address_line1' => '100 Main St',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
        ];
    }

    private function fillQuoteReady(LtlShipment $shipment): LtlShipment
    {
        $shipment->update([
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@example.com',
            'contact_phone' => '555-0100',
            'time_mode' => LtlShipment::TIME_ASAP,
            'load_requirement' => 'dock',
            'pickup_type' => 'business',
        ]);
        $shipment->pallets()->create([
            'sort_order' => 1,
            'commodity' => 'Boxes',
            'length_in' => 48,
            'width_in' => 40,
            'height_in' => 60,
            'weight_lbs' => 500,
        ]);

        return $shipment->fresh(['pallets']);
    }

    public function test_staff_create_assigns_sequential_numbers(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $first = $this->postJson('/api/admin/ltl-shipments', $this->createPayload($account->id));
        $first->assertCreated()->assertJsonPath('shipment.number', 'LTL #001')
            ->assertJsonPath('shipment.status', 'draft');

        $second = $this->postJson('/api/admin/ltl-shipments', $this->createPayload($account->id));
        $second->assertCreated()->assertJsonPath('shipment.number', 'LTL #002');
    }

    public function test_portal_create_scopes_to_own_account(): void
    {
        $account = $this->account('portal');
        $other = $this->account('other');
        Sanctum::actingAs($this->portalUser($account));

        $response = $this->postJson('/api/ltl-shipments', [
            'client_account_id' => $other->id,
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Portal Pickup',
            'address_line1' => '200 Oak Ave',
            'city' => 'Orlando',
            'state' => 'FL',
            'zip' => '32801',
        ]);

        $response->assertCreated()
            ->assertJsonPath('shipment.client_account_id', $account->id)
            ->assertJsonPath('shipment.number', 'LTL #001');
    }

    public function test_portal_cannot_view_other_account_ltl(): void
    {
        $accountA = $this->account('a');
        $accountB = $this->account('b');
        $shipmentB = LtlShipment::create([
            'client_account_id' => $accountB->id,
            'number' => 'LTL #001',
            'status' => LtlShipment::STATUS_DRAFT,
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Other Co',
            'address_line1' => '1 Other St',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33101',
        ]);

        Sanctum::actingAs($this->portalUser($accountA));

        $this->getJson('/api/ltl-shipments/'.$shipmentB->id)->assertForbidden();

        $list = $this->getJson('/api/ltl-shipments');
        $list->assertOk();
        $this->assertSame([], $list->json('data'));
    }

    public function test_get_quote_validates_then_sets_pending_and_slacks(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/ltl-shipments', $this->createPayload($account->id));
        $id = (int) $create->json('shipment.id');

        $this->postJson('/api/admin/ltl-shipments/'.$id.'/request-quote')
            ->assertStatus(422);

        $shipment = LtlShipment::query()->findOrFail($id);
        $this->fillQuoteReady($shipment);

        $slack = Mockery::mock(SlackDeliveryService::class);
        $slack->shouldReceive('hasBotToken')->andReturn(true);
        $slack->shouldReceive('post')
            ->once()
            ->withArgs(function ($channel, $text, $username) {
                return $channel === '#ltl-freight'
                    && $username === 'LTL Quote Request'
                    && str_contains((string) $text, 'Ship To Save Rack')
                    && str_contains((string) $text, 'Pallets: 1')
                    && str_contains((string) $text, 'View LTL');
            })
            ->andReturn(['method' => 'bot', 'channel' => '#ltl-freight', 'ts' => '1.0']);
        $this->app->instance(SlackDeliveryService::class, $slack);

        $this->postJson('/api/admin/ltl-shipments/'.$id.'/request-quote')
            ->assertOk()
            ->assertJsonPath('shipment.status', 'pending');
    }

    public function test_status_quoted_and_scheduled_send_slack(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $shipment = LtlShipment::create([
            'client_account_id' => $account->id,
            'number' => 'LTL #010',
            'status' => LtlShipment::STATUS_PENDING,
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Ready Co',
            'address_line1' => '9 Ready Rd',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
            'quote_amount_cents' => 12500,
            'time_mode' => LtlShipment::TIME_ASAP,
        ]);

        $slack = Mockery::mock(SlackDeliveryService::class);
        $slack->shouldReceive('hasBotToken')->andReturn(true);
        $slack->shouldReceive('post')
            ->once()
            ->withArgs(function ($channel, $text, $username) {
                return $channel === '#ltl-freight'
                    && $username === 'LTL Quote Ready'
                    && str_contains((string) $text, 'Quote: $125.00');
            })
            ->andReturn(['method' => 'bot', 'channel' => '#ltl-freight', 'ts' => '1.0']);
        $slack->shouldReceive('post')
            ->once()
            ->withArgs(function ($channel, $text, $username) {
                return $channel === '#ltl-freight'
                    && $username === 'LTL Scheduled'
                    && str_contains((string) $text, 'Pick Up Date: As soon as possible');
            })
            ->andReturn(['method' => 'bot', 'channel' => '#ltl-freight', 'ts' => '1.0']);
        $this->app->instance(SlackDeliveryService::class, $slack);

        $this->patchJson('/api/admin/ltl-shipments/'.$shipment->id.'/status', [
            'status' => LtlShipment::STATUS_QUOTED,
        ])->assertOk()->assertJsonPath('shipment.status', 'quoted');

        $this->patchJson('/api/admin/ltl-shipments/'.$shipment->id.'/status', [
            'status' => LtlShipment::STATUS_SCHEDULED,
        ])->assertOk()->assertJsonPath('shipment.status', 'scheduled');
    }

    public function test_portal_cannot_change_status(): void
    {
        $account = $this->account();
        $shipment = LtlShipment::create([
            'client_account_id' => $account->id,
            'number' => 'LTL #020',
            'status' => LtlShipment::STATUS_PENDING,
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Portal Co',
            'address_line1' => '1 Portal St',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
        ]);

        Sanctum::actingAs($this->portalUser($account));

        $this->patchJson('/api/ltl-shipments/'.$shipment->id.'/status', [
            'status' => LtlShipment::STATUS_QUOTED,
        ])->assertNotFound();
    }
}
