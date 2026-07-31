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

    public function test_create_project_assigns_pid_without_custom_bill(): void
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
        $res->assertJsonPath('status', Project::STATUS_DRAFT);
        $this->assertNull($res->json('custom_bill_id'));
        $this->assertNull($res->json('completed_at'));
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
            'name' => 'Draft One',
        ])->json('id');
        $b = $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Will Complete',
        ])->json('id');
        $this->patchJson('/api/projects/'.$b.'/status', [
            'status' => Project::STATUS_COMPLETED,
        ])->assertOk();

        $summary = $this->getJson('/api/projects/summary')->assertOk()->json();
        $this->assertSame(1, (int) $summary['draft']);
        $this->assertSame(1, (int) $summary['completed']);

        $draft = $this->getJson('/api/projects?status=draft')->assertOk()->json('data');
        $this->assertCount(1, $draft);
        $this->assertSame((int) $a, (int) $draft[0]['id']);

        $all = $this->getJson('/api/projects')->assertOk()->json('data');
        $this->assertCount(2, $all);
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
        $this->assertNull($quote->json('custom_bill_id'));

        $note = $this->postJson('/api/projects/'.$id.'/notes', [
            'body' => 'Internal kickoff notes',
        ])->assertCreated();
        $this->assertSame('Internal kickoff notes', $note->json('body'));

        $show = $this->getJson('/api/projects/'.$id)->assertOk();
        $this->assertCount(1, $show->json('notes'));
    }

    public function test_create_bill_from_project_can_be_open(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Bill Co',
            'email' => 'bill-proj@example.test',
        ]);
        $id = (int) $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Billable Project',
        ])->json('id');

        $this->postJson('/api/projects/'.$id.'/quote-items', [
            'line_type' => CustomBillLineType::acceptedLineTypes()[0] ?? 'fulfillment',
            'name' => 'Install',
            'quantity' => 1,
            'unit_price' => 100,
        ])->assertCreated();

        $res = $this->postJson('/api/projects/'.$id.'/create-bill', [
            'status' => CustomBill::STATUS_OPEN,
        ]);
        $res->assertCreated();
        $res->assertJsonPath('custom_bill_status', CustomBill::STATUS_OPEN);
        $this->assertNotNull($res->json('custom_bill_id'));

        $bill = CustomBill::query()->find($res->json('custom_bill_id'));
        $this->assertNotNull($bill);
        $this->assertSame(CustomBill::STATUS_OPEN, $bill->status);
        $this->assertSame('Billable Project', $bill->name);
        $this->assertSame(1, $bill->items()->count());
    }

    public function test_create_bill_from_project_defaults_to_draft(): void
    {
        $this->staffWithProjects();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Draft Bill Co',
            'email' => 'draft-bill-proj@example.test',
        ]);
        $id = (int) $this->postJson('/api/projects', [
            'client_account_id' => $account->id,
            'name' => 'Draft Bill Project',
        ])->json('id');

        $this->postJson('/api/projects/'.$id.'/quote-items', [
            'line_type' => CustomBillLineType::acceptedLineTypes()[0] ?? 'fulfillment',
            'name' => 'Setup',
            'quantity' => 1,
            'unit_price' => 50,
        ])->assertCreated();

        $res = $this->postJson('/api/projects/'.$id.'/create-bill')->assertCreated();
        $res->assertJsonPath('custom_bill_status', CustomBill::STATUS_DRAFT);
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

        $this->postJson('/api/projects/'.$id.'/quote-items', [
            'line_type' => CustomBillLineType::acceptedLineTypes()[0] ?? 'fulfillment',
            'name' => 'Work',
            'quantity' => 1,
            'unit_price' => 25,
        ])->assertCreated();

        $billId = (int) $this->postJson('/api/projects/'.$id.'/create-bill', [
            'status' => CustomBill::STATUS_OPEN,
        ])->json('custom_bill_id');

        $this->deleteJson('/api/projects/'.$id)->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $id]);
        $this->assertDatabaseHas('custom_bills', ['id' => $billId, 'name' => 'Temp Project']);
    }
}
