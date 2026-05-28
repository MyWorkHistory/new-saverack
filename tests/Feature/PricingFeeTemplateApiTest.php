<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PricingFeeTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private function settingsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'settings.view'],
            ['label' => 'View settings', 'module' => 'settings']
        );
    }

    private function settingsUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'settings.update'],
            ['label' => 'Update settings', 'module' => 'settings']
        );
    }

    private function actingWithSettings(array $keys): User
    {
        $user = User::factory()->create();
        $permIds = [];
        foreach ($keys as $key) {
            if ($key === 'settings.view') {
                $permIds[] = $this->settingsViewPermission()->id;
            } elseif ($key === 'settings.update') {
                $permIds[] = $this->settingsUpdatePermission()->id;
            }
        }
        $user->permissions()->sync($permIds);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_pricing_fees(): void
    {
        $this->getJson('/api/settings/pricing-fees')->assertUnauthorized();
    }

    public function test_user_without_settings_view_cannot_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/settings/pricing-fees')->assertForbidden();
    }

    public function test_admin_can_create_and_list_pricing_fee(): void
    {
        $this->actingAsAdmin();

        ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Fee Client',
            'email' => 'fee-client@example.test',
        ]);

        $create = $this->postJson('/api/settings/pricing-fees', [
            'name' => 'Label Fee',
            'description' => 'Per label',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 0.5,
        ]);

        $create->assertCreated();
        $create->assertJsonPath('name', 'Label Fee');

        $list = $this->getJson('/api/settings/pricing-fees');
        $list->assertOk();
        $list->assertJsonPath('data.0.name', 'Label Fee');

        $templateId = (int) $create->json('id');
        $this->assertGreaterThan(
            0,
            ClientAccountFee::query()->where('pricing_template_id', $templateId)->count()
        );
    }

    public function test_staff_with_legacy_settings_permissions_cannot_update_fee(): void
    {
        $this->actingWithSettings(['settings.view', 'settings.update']);

        $template = PricingFeeTemplate::query()->create([
            'name' => 'Old Name',
            'category' => PricingFeeTemplate::CATEGORY_STORAGE,
            'amount' => 1,
            'sort_order' => 0,
        ]);

        $this->patchJson('/api/settings/pricing-fees/'.$template->id, [
            'name' => 'New Name',
            'amount' => 3.5,
        ])->assertForbidden();
    }
}
