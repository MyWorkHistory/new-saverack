<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use App\Support\Billing\CustomBillLineType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function attachPerms(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $perm = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => explode('.', $key)[0] ?? 'crm']
            );
            $user->permissions()->attach($perm->id);
        }
    }

    private function staffWithProjects(array $keys = ['projects.view', 'projects.create', 'projects.update', 'projects.delete']): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $this->attachPerms($user, $keys);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_unauthorized_user_cannot_list_projects(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/projects')->assertForbidden();
    }

    public function test_create_project_assigns_pid_and_custom_bill(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Project Co',
            'email' => 'project@example.test',
        ]);

        $res = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Website Refresh',
            'description' => 'Rebuild storefront',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('pid', 'P-1001');
        $res->assertJsonPath('name', 'Website Refresh');
        $res->assertJsonPath('status', Project::STATUS_PENDING);
        $this->assertNotNull($res->json('custom_bill_id'));
        $this->assertNull($res->json('completed_at'));

        $bill = CustomBill::query()->find($res->json('custom_bill_id'));
        $this->assertNotNull($bill);
        $this->assertSame('P-1001', $bill->name);
        $this->assertSame(CustomBill::STATUS_OPEN, $bill->status);
        $this->assertSame((int) $account->id, (int) $bill->client_account_id);
    }

    public function test_complete_status_sets_completed_at(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Done Co',
            'email' => 'done@example.test',
        ]);
        $create = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Finish Me',
        ])->assertCreated();

        $id = (int) $create->json('id');
        $res = $this->patchJson('/api/projects/'.$id.'/status', [
            'status' => Project::STATUS_COMPLETED,
        ]);
        $res->assertOk();
        $res->assertJsonPath('status', Project::STATUS_COMPLETED);
        $this->assertNotNull($res->json('completed_at'));

        $back = $this->patchJson('/api/projects/'.$id.'/status', [
            'status' => Project::STATUS_IN_PROGRESS,
        ])->assertOk();
        $this->assertNull($back->json('completed_at'));
    }

    public function test_summary_and_list_filter_by_status(): void
    {
        $this->staffWithProjects(['projects.view', 'projects.create', 'projects.update']);
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Filter Co',
            'email' => 'filter@example.test',
        ]);
        $a = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Pending One',
        ])->json('id');
        $b = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Will Complete',
        ])->json('id');
        $this->patchJson('/api/projects/'.$b.'/status', [
            'status' => Project::STATUS_COMPLETED,
        ])->assertOk();

        $summary = $this->getJson('/api/projects/summary')->assertOk()->json();
        $this->assertSame(1, (int) $summary['pending']);
        $this->assertSame(1, (int) $summary['completed']);

        $pending = $this->getJson('/api/projects?status=pending')->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $this->assertSame((int) $a, (int) $pending[0]['id']);
    }

    public function test_add_quote_item_and_note(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Quote Co',
            'email' => 'quote@example.test',
        ]);
        $id = (int) $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Quoted Project',
        ])->json('id');

        $quote = $this->postJson('/api/projects/'.$id.'/quote-items', [
            'line_type' => CustomBillLineType::acceptedLineTypes()[0] ?? 'fulfillment',
            'name' => 'Design Sprint',
            'quantity' => 2,
            'unit_price' => 150.5,
        ]);
        $quote->assertCreated();
        $this->assertCount(1, $quote->json('quote_items'));
        $this->assertSame('Design Sprint', $quote->json('quote_items.0.name'));
        $this->assertGreaterThan(0, (int) $quote->json('quote_total_cents'));

        $billId = (int) $quote->json('custom_bill_id');
        $this->assertDatabaseHas('custom_bill_items', [
            'custom_bill_id' => $billId,
            'name' => 'Design Sprint',
        ]);

        $note = $this->postJson('/api/projects/'.$id.'/notes', [
            'body' => 'Internal kickoff notes',
        ])->assertCreated();
        $this->assertSame('Internal kickoff notes', $note->json('body'));

        $show = $this->getJson('/api/projects/'.$id)->assertOk();
        $this->assertCount(1, $show->json('notes'));
    }

    public function test_delete_project_keeps_custom_bill(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Delete Co',
            'email' => 'delete-proj@example.test',
        ]);
        $create = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Temp Project',
        ])->assertCreated();
        $id = (int) $create->json('id');
        $billId = (int) $create->json('custom_bill_id');

        $this->deleteJson('/api/projects/'.$id)->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $id]);
        $this->assertDatabaseHas('custom_bills', ['id' => $billId, 'name' => 'P-1001']);
    }
}
