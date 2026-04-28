<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientAccountService
{
    /** @var PortalClientProvisioningService */
    protected $portalProvisioning;

    /** @var ActivityLogService */
    protected $activityLog;

    public function __construct(
        PortalClientProvisioningService $portalProvisioning,
        ActivityLogService $activityLog
    ) {
        $this->portalProvisioning = $portalProvisioning;
        $this->activityLog = $activityLog;
    }

    public function filteredAccountsQuery(array $filters): Builder
    {
        return ClientAccount::query()
            ->with([
                'accountManager' => function ($q) {
                    $q->select('users.id', 'users.name', 'users.email');
                },
                'primaryAccountUser' => function ($q) {
                    $q->select('users.id', 'users.email', 'users.client_account_id', 'users.is_account_primary')
                        ->with(['profile:id,user_id,avatar_path']);
                },
                'feeItems',
            ])
            ->tap(fn (Builder $q) => $this->applyAccountDirectoryFilters($q, $filters));
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 500);
        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = [
            'id', 'company_name', 'email', 'status', 'created_at', 'updated_at', 'contract_date',
        ];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }

        $query = $this->filteredAccountsQuery($filters)->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    private function applyAccountDirectoryFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $managerId = isset($filters['account_manager_id']) && $filters['account_manager_id'] !== '' && $filters['account_manager_id'] !== 'all'
            ? (int) $filters['account_manager_id']
            : null;
        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        $statusFilter = $status !== '' && $status !== 'all' && in_array($status, ClientAccount::STATUSES, true)
            ? $status
            : null;

        $query
            ->when($statusFilter !== null, function ($q) use ($statusFilter) {
                $q->where('client_accounts.status', $statusFilter);
            })
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($nested) use ($like) {
                    $nested->where('company_name', 'like', $like)
                        ->orWhere('brand_name', 'like', $like)
                        ->orWhere('website', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('contact_first_name', 'like', $like)
                        ->orWhere('contact_last_name', 'like', $like);
                });
            })
            ->when($managerId !== null && $managerId > 0, function ($q) use ($managerId) {
                $q->where('account_manager_id', $managerId);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $portalPlainPassword  Primary admin portal user (always created).
     */
    public function create(array $data, string $portalPlainPassword, ?User $actor = null): ClientAccount
    {
        $data['status'] = $data['status'] ?? ClientAccount::STATUS_PENDING;

        $account = ClientAccount::query()->create($data);

        $this->ensureDefaultFeeItems($account);

        $fullName = $account->contactFullName();
        if ($fullName === '' || trim($fullName) === '') {
            throw new InvalidArgumentException('Full name is required to create a portal login.');
        }
        $this->portalProvisioning->attachPortalLoginToAccount($account, $fullName, $portalPlainPassword);

        $account = $account->fresh(['accountManager', 'primaryAccountUser']);

        if ($actor !== null) {
            $this->activityLog->log($actor, 'client_account.created', $account, null, [
                'company_name' => (string) $account->company_name,
            ]);
            $primary = $account->primaryAccountUser;
            if ($primary !== null) {
                $this->activityLog->log($actor, 'portal_user.created', $primary, null, [
                    'email' => (string) $primary->email,
                ]);
            }
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientAccount $account, array $data, ?User $actor = null): ClientAccount
    {
        if ($data !== []) {
            $account->update($data);
        }

        $account = $account->fresh(['accountManager']);

        if ($actor !== null && $data !== []) {
            $this->activityLog->log($actor, 'client_account.updated', $account, null, [
                'fields' => array_keys($data),
            ]);
        }

        return $account;
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        return ClientAccount::query()->whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkDelete(array $ids): int
    {
        $accounts = ClientAccount::query()->whereIn('id', $ids)->get();
        foreach ($accounts as $account) {
            $account->delete();
        }

        return $accounts->count();
    }

    public function delete(ClientAccount $account): void
    {
        $account->delete();
    }

    /**
     * Users that may be assigned as account managers — anyone who can appear in the Staff directory.
     * (All rows in `users`; the CRM Staff list is not limited to specific role *names*, and production
     * databases may use different role naming. Assignment is still validated as an existing user id.)
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,email:string}>
     */
    public function accountManagersForMeta()
    {
        return User::query()
            ->whereNull('client_account_id')
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(function (User $u) {
                return [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ];
            });
    }

    /**
     * Ensure the four standard fee rows exist (fulfillment + returns). Safe to call repeatedly.
     */
    public function ensureDefaultFeeItems(ClientAccount $account): void
    {
        $defaults = [
            [ClientAccountFee::GROUP_FULFILLMENT, ClientAccountFee::LINE_FIRST_PICK],
            [ClientAccountFee::GROUP_FULFILLMENT, ClientAccountFee::LINE_ADDITIONAL_PICKS],
            [ClientAccountFee::GROUP_RETURNS, ClientAccountFee::LINE_RETURNS_PROCESSING],
            [ClientAccountFee::GROUP_RETURNS, ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS],
        ];

        foreach ($defaults as [$group, $line]) {
            ClientAccountFee::query()->firstOrCreate(
                [
                    'client_account_id' => $account->id,
                    'fee_group' => $group,
                    'line_code' => $line,
                ],
                [
                    'label' => null,
                    'amount' => '0.0000',
                    'currency' => 'USD',
                    'sort_order' => 0,
                ]
            );
        }
    }

    /**
     * @param  array{fulfillment?: array<string, mixed>, returns?: array<string, mixed>, storage?: list<array<string, mixed>>}  $data
     */
    public function syncFees(ClientAccount $account, array $data, ?User $actor = null): ClientAccount
    {
        DB::transaction(function () use ($account, $data) {
            $this->ensureDefaultFeeItems($account);

            $this->updateStandardFeeAmount(
                $account,
                ClientAccountFee::GROUP_FULFILLMENT,
                ClientAccountFee::LINE_FIRST_PICK,
                $data['fulfillment']['first_pick_fee'] ?? null
            );
            $this->updateStandardFeeAmount(
                $account,
                ClientAccountFee::GROUP_FULFILLMENT,
                ClientAccountFee::LINE_ADDITIONAL_PICKS,
                $data['fulfillment']['additional_picks_fee'] ?? null
            );
            $this->updateStandardFeeAmount(
                $account,
                ClientAccountFee::GROUP_RETURNS,
                ClientAccountFee::LINE_RETURNS_PROCESSING,
                $data['returns']['processing_fee'] ?? null
            );
            $this->updateStandardFeeAmount(
                $account,
                ClientAccountFee::GROUP_RETURNS,
                ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
                $data['returns']['additional_items_fee'] ?? null
            );

            $storageInput = $data['storage'] ?? [];
            $keptIds = [];
            $nextSort = (int) (ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('fee_group', ClientAccountFee::GROUP_STORAGE)
                ->max('sort_order'));

            foreach ($storageInput as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                $label = isset($row['label']) ? trim((string) $row['label']) : '';
                $currency = strtoupper(trim((string) ($row['currency'] ?? 'USD')));
                if (strlen($currency) !== 3) {
                    $currency = 'USD';
                }

                if ($id > 0) {
                    $fee = ClientAccountFee::query()
                        ->where('client_account_id', $account->id)
                        ->where('id', $id)
                        ->where('fee_group', ClientAccountFee::GROUP_STORAGE)
                        ->first();
                    if ($fee !== null) {
                        $fee->update([
                            'label' => $label !== '' ? $label : ($fee->label ?? 'Storage fee'),
                            'amount' => $this->normalizeFeeAmount($row['amount'] ?? null),
                            'currency' => $currency,
                        ]);
                        $keptIds[] = $fee->id;
                    }

                    continue;
                }

                if ($label === '' && ($row['amount'] ?? null) === null) {
                    continue;
                }

                $nextSort++;
                $created = ClientAccountFee::query()->create([
                    'client_account_id' => $account->id,
                    'fee_group' => ClientAccountFee::GROUP_STORAGE,
                    'line_code' => null,
                    'label' => $label !== '' ? $label : 'Storage fee',
                    'amount' => $this->normalizeFeeAmount($row['amount'] ?? null),
                    'currency' => $currency,
                    'sort_order' => $nextSort,
                ]);
                $keptIds[] = $created->id;
            }

            ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('fee_group', ClientAccountFee::GROUP_STORAGE)
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        if ($actor !== null) {
            $this->activityLog->log($actor, 'client_account.updated', $account, null, [
                'fields' => ['fees'],
            ]);
        }

        $fresh = $account->fresh(['feeItems']);

        return $fresh !== null ? $fresh : $account;
    }

    /**
     * @param  mixed  $rawAmount
     */
    private function updateStandardFeeAmount(ClientAccount $account, string $group, string $lineCode, $rawAmount): void
    {
        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('fee_group', $group)
            ->where('line_code', $lineCode)
            ->first();

        if ($fee === null) {
            $this->ensureDefaultFeeItems($account);
            $fee = ClientAccountFee::query()
                ->where('client_account_id', $account->id)
                ->where('fee_group', $group)
                ->where('line_code', $lineCode)
                ->first();
        }

        if ($fee !== null) {
            $fee->update(['amount' => $this->normalizeFeeAmount($rawAmount)]);
        }
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeFeeAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function feesPayloadForApi(ClientAccount $account): array
    {
        $items = $account->relationLoaded('feeItems')
            ? $account->feeItems
            : $account->feeItems()->get();

        $fulfillment = [];
        $returns = [];
        $storage = [];

        foreach ($items as $fee) {
            if (! $fee instanceof ClientAccountFee) {
                continue;
            }
            $amount = $fee->amount !== null ? (float) $fee->amount : null;

            if ($fee->fee_group === ClientAccountFee::GROUP_FULFILLMENT) {
                if ($fee->line_code === ClientAccountFee::LINE_FIRST_PICK) {
                    $fulfillment['first_pick_fee'] = $amount;
                    $fulfillment['first_pick_item_id'] = $fee->id;
                } elseif ($fee->line_code === ClientAccountFee::LINE_ADDITIONAL_PICKS) {
                    $fulfillment['additional_picks_fee'] = $amount;
                    $fulfillment['additional_picks_item_id'] = $fee->id;
                }
            } elseif ($fee->fee_group === ClientAccountFee::GROUP_RETURNS) {
                if ($fee->line_code === ClientAccountFee::LINE_RETURNS_PROCESSING) {
                    $returns['processing_fee'] = $amount;
                    $returns['processing_item_id'] = $fee->id;
                } elseif ($fee->line_code === ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS) {
                    $returns['additional_items_fee'] = $amount;
                    $returns['additional_items_item_id'] = $fee->id;
                }
            } elseif ($fee->fee_group === ClientAccountFee::GROUP_STORAGE) {
                $storage[] = [
                    'id' => $fee->id,
                    'label' => $fee->label !== null && $fee->label !== '' ? (string) $fee->label : 'Storage fee',
                    'amount' => $amount,
                    'currency' => $fee->currency !== null && $fee->currency !== '' ? (string) $fee->currency : 'USD',
                ];
            }
        }

        return [
            'fulfillment' => $fulfillment,
            'returns' => $returns,
            'storage' => $storage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(ClientAccount $account): array
    {
        $account->loadMissing([
            'accountManager:id,name,email',
            'primaryAccountUser:id,email,client_account_id,is_account_primary',
            'feeItems',
        ]);
        $primaryLogin = $account->primaryAccountUser;
        if ($primaryLogin !== null) {
            $primaryLogin->loadMissing(['profile:id,user_id,avatar_path']);
        }
        $manager = $account->accountManager;

        $primaryAvatarUrl = null;
        if ($primaryLogin !== null && $primaryLogin->relationLoaded('profile') && $primaryLogin->profile !== null) {
            $primaryAvatarUrl = $primaryLogin->profile->avatar_url;
        }

        return [
            'id' => $account->id,
            'status' => $account->status,
            'company_name' => $account->company_name,
            'brand_name' => $account->brand_name,
            'website' => $account->website,
            'contact_first_name' => $account->contact_first_name,
            'contact_last_name' => $account->contact_last_name,
            'contact_full_name' => $account->contactFullName(),
            'email' => $account->email,
            'phone' => $account->phone,
            'notify_email' => (bool) $account->notify_email,
            'notification_email' => $account->notification_email,
            'telegram_handle' => $account->telegram_handle,
            'whatsapp_e164' => $account->whatsapp_e164,
            'slack_channel' => $account->slack_channel,
            'in_house_slack' => $account->in_house_slack,
            'street' => $account->street,
            'city' => $account->city,
            'state' => $account->state,
            'zip' => $account->zip,
            'country' => $account->country,
            'notes' => $account->notes,
            'account_manager_id' => $account->account_manager_id,
            'default_payment_type' => $account->default_payment_type,
            'cc_fee_percent' => $account->cc_fee_percent !== null ? (float) $account->cc_fee_percent : null,
            'stripe_customer_id' => $account->stripe_customer_id,
            'shiphero_customer_account_id' => $account->shiphero_customer_account_id,
            'whatsapp_api_id' => $account->whatsapp_api_id,
            'account_manager' => $manager !== null
                ? ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email]
                : null,
            'primary_account_user_id' => $primaryLogin !== null ? $primaryLogin->id : null,
            'portal_login_email' => $primaryLogin !== null ? $primaryLogin->email : null,
            'primary_avatar_url' => $primaryAvatarUrl,
            'contract_date' => $account->contract_date !== null
                ? $account->contract_date->format('Y-m-d')
                : null,
            'created_at' => $account->created_at !== null
                ? $account->created_at->toIso8601String()
                : null,
            'updated_at' => $account->updated_at !== null
                ? $account->updated_at->toIso8601String()
                : null,
            'fees' => $this->feesPayloadForApi($account),
        ];
    }
}
