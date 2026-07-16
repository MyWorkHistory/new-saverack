<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsnBill;
use App\Models\CustomBill;
use App\Models\ReturnBill;
use App\Services\BillingBillsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingBillsController extends Controller
{
    /** @var BillingBillsService */
    private $bills;

    public function __construct(BillingBillsService $bills)
    {
        $this->bills = $bills;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canCustom = $user->can('viewAny', CustomBill::class);
        $canAsn = $user->can('viewAny', AsnBill::class);
        $canReturn = $user->can('viewAny', ReturnBill::class);

        if (! $canCustom && ! $canAsn && ! $canReturn) {
            abort(403);
        }

        $data = $this->bills->paginate($request->only([
            'search',
            'status',
            'client_account_id',
            'date_from',
            'date_to',
            'bill_kind',
            'sort_dir',
            'per_page',
            'page',
        ]), $user);

        return response()->json($data);
    }
}
