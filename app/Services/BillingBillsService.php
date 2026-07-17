<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillingBillsService
{
    /**
     * Unified paginated list of Custom + ASN + Return bills.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function paginate(array $filters, User $viewer): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $kinds = $this->allowedKinds($viewer, $filters['bill_kind'] ?? null);
        if ($kinds === []) {
            return [
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ];
        }

        $builders = [];
        if (in_array('custom', $kinds, true)) {
            $builders[] = $this->customQuery($filters);
        }
        if (in_array('asn', $kinds, true)) {
            $builders[] = $this->asnQuery($filters);
        }
        if (in_array('return', $kinds, true)) {
            $builders[] = $this->returnQuery($filters);
        }

        $first = array_shift($builders);
        foreach ($builders as $b) {
            $first->unionAll($b);
        }

        $union = DB::query()->fromSub($first, 'bills');
        $total = (clone $union)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $rows = $union
            ->orderBy('bill_date', $sortDir)
            ->orderBy('id', $sortDir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $rows->map(function ($row) {
                return $this->rowToArray($row);
            })->values()->all(),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedKinds(User $viewer, $billKind): array
    {
        $all = [];
        if ($viewer->isAdministrator() || $viewer->isCrmOwner() || $viewer->hasPermission('billing_custom_bills.view')) {
            $all[] = 'custom';
        }
        if ($viewer->isAdministrator() || $viewer->isCrmOwner() || $viewer->hasPermission('billing_asn_bills.view')) {
            $all[] = 'asn';
        }
        if ($viewer->isAdministrator() || $viewer->isCrmOwner() || $viewer->hasPermission('billing_return_bills.view')) {
            $all[] = 'return';
        }

        $want = strtolower(trim((string) $billKind));
        if ($want !== '' && $want !== 'all') {
            if (! in_array($want, $all, true)) {
                return [];
            }

            return [$want];
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function customQuery(array $filters)
    {
        $q = DB::table('custom_bills')
            ->leftJoin('client_accounts', 'custom_bills.client_account_id', '=', 'client_accounts.id')
            ->leftJoin('projects', 'projects.custom_bill_id', '=', 'custom_bills.id')
            ->selectRaw("
                'custom' as bill_kind,
                custom_bills.id as id,
                custom_bills.bill_number as bill_number,
                COALESCE(custom_bills.name, CAST(custom_bills.bill_number AS CHAR)) as display_name,
                custom_bills.status as status,
                custom_bills.client_account_id as client_account_id,
                client_accounts.company_name as client_account_name,
                custom_bills.bill_date as bill_date,
                custom_bills.total_cents as total_cents,
                custom_bills.invoice_id as invoice_id,
                CASE WHEN projects.pid IS NOT NULL THEN 'Project #' ELSE NULL END as ref_label,
                projects.pid as ref_value,
                projects.id as project_id,
                (SELECT COUNT(*) FROM custom_bill_items WHERE custom_bill_items.custom_bill_id = custom_bills.id) as items_count
            ");

        $this->applyCommonFilters($q, $filters, 'custom_bills', function ($query, $search) {
            $query->where(function ($inner) use ($search) {
                if (ctype_digit($search)) {
                    $inner->orWhere('custom_bills.bill_number', (int) $search);
                }
                $inner->orWhere('custom_bills.name', 'like', '%'.$search.'%')
                    ->orWhere('client_accounts.company_name', 'like', '%'.$search.'%')
                    ->orWhere('projects.pid', 'like', '%'.$search.'%');
            });
        });

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function asnQuery(array $filters)
    {
        $q = DB::table('asn_bills')
            ->leftJoin('client_accounts', 'asn_bills.client_account_id', '=', 'client_accounts.id')
            ->leftJoin('client_account_asns', 'asn_bills.client_account_asn_id', '=', 'client_account_asns.id')
            ->selectRaw("
                'asn' as bill_kind,
                asn_bills.id as id,
                asn_bills.bill_number as bill_number,
                CAST(asn_bills.bill_number AS CHAR) as display_name,
                asn_bills.status as status,
                asn_bills.client_account_id as client_account_id,
                client_accounts.company_name as client_account_name,
                asn_bills.bill_date as bill_date,
                asn_bills.total_cents as total_cents,
                asn_bills.invoice_id as invoice_id,
                CASE WHEN client_account_asns.asn_number IS NOT NULL THEN 'ASN #' ELSE NULL END as ref_label,
                client_account_asns.asn_number as ref_value,
                NULL as project_id,
                (SELECT COUNT(*) FROM asn_bill_items WHERE asn_bill_items.asn_bill_id = asn_bills.id) as items_count
            ");

        $this->applyCommonFilters($q, $filters, 'asn_bills', function ($query, $search) {
            $query->where(function ($inner) use ($search) {
                if (ctype_digit($search)) {
                    $inner->orWhere('asn_bills.bill_number', (int) $search);
                }
                $inner->orWhere('client_accounts.company_name', 'like', '%'.$search.'%')
                    ->orWhere('client_account_asns.asn_number', 'like', '%'.$search.'%');
            });
        });

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function returnQuery(array $filters)
    {
        $q = DB::table('return_bills')
            ->leftJoin('client_accounts', 'return_bills.client_account_id', '=', 'client_accounts.id')
            ->leftJoin('client_account_returns', 'return_bills.client_account_return_id', '=', 'client_account_returns.id')
            ->selectRaw("
                'return' as bill_kind,
                return_bills.id as id,
                return_bills.bill_number as bill_number,
                CAST(return_bills.bill_number AS CHAR) as display_name,
                return_bills.status as status,
                return_bills.client_account_id as client_account_id,
                client_accounts.company_name as client_account_name,
                return_bills.bill_date as bill_date,
                return_bills.total_cents as total_cents,
                return_bills.invoice_id as invoice_id,
                CASE WHEN client_account_returns.rma_number IS NOT NULL THEN 'RMA' ELSE NULL END as ref_label,
                client_account_returns.rma_number as ref_value,
                NULL as project_id,
                (SELECT COUNT(*) FROM return_bill_items WHERE return_bill_items.return_bill_id = return_bills.id) as items_count
            ");

        $this->applyCommonFilters($q, $filters, 'return_bills', function ($query, $search) {
            $query->where(function ($inner) use ($search) {
                if (ctype_digit($search)) {
                    $inner->orWhere('return_bills.bill_number', (int) $search);
                }
                $inner->orWhere('client_accounts.company_name', 'like', '%'.$search.'%')
                    ->orWhere('client_account_returns.rma_number', 'like', '%'.$search.'%')
                    ->orWhere('client_account_returns.order_number', 'like', '%'.$search.'%');
            });
        });

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  callable  $searchCallback
     */
    private function applyCommonFilters($query, array $filters, string $table, $searchCallback): void
    {
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where($table.'.status', (string) $filters['status']);
        }
        if (! empty($filters['client_account_id'])) {
            $query->where($table.'.client_account_id', (int) $filters['client_account_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate($table.'.bill_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate($table.'.bill_date', '<=', (string) $filters['date_to']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $searchCallback($query, $search);
        }
    }

    /**
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function rowToArray($row): array
    {
        $kind = (string) $row->bill_kind;
        $id = (int) $row->id;
        $status = (string) $row->status;
        $detailPath = '/admin/billing/bills/'.$id;
        if ($kind === 'asn') {
            $detailPath = '/admin/billing/asn-bills/'.$id;
        } elseif ($kind === 'return') {
            $detailPath = '/admin/billing/return-bills/'.$id;
        }

        $kindLabel = 'Custom';
        if ($kind === 'asn') {
            $kindLabel = 'ASN';
        } elseif ($kind === 'return') {
            $kindLabel = 'Return';
        }

        return [
            'bill_kind' => $kind,
            'bill_kind_label' => $kindLabel,
            'id' => $id,
            'bill_number' => (int) $row->bill_number,
            'display_name' => (string) $row->display_name,
            'status' => $status,
            'status_label' => $status === 'open' ? 'Open' : 'Invoiced',
            'client_account_id' => $row->client_account_id ? (int) $row->client_account_id : null,
            'client_account_name' => (string) ($row->client_account_name ?? ''),
            'bill_date' => $row->bill_date ? (string) $row->bill_date : null,
            'total_cents' => (int) $row->total_cents,
            'invoice_id' => $row->invoice_id ? (int) $row->invoice_id : null,
            'ref_label' => $row->ref_label ? (string) $row->ref_label : null,
            'ref_value' => $row->ref_value ? (string) $row->ref_value : null,
            'project_id' => isset($row->project_id) && $row->project_id ? (int) $row->project_id : null,
            'items_count' => (int) ($row->items_count ?? 0),
            'detail_path' => $detailPath,
        ];
    }
}
