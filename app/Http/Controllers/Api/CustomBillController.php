<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomBillAddToInvoiceRequest;
use App\Http\Requests\CustomBillItemStoreRequest;
use App\Http\Requests\CustomBillItemUpdateRequest;
use App\Http\Requests\CustomBillStatusRequest;
use App\Http\Requests\CustomBillStoreRequest;
use App\Http\Requests\CustomBillUpdateRequest;
use App\Models\CustomBill;
use App\Models\CustomBillItem;
use App\Services\CustomBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomBillController extends Controller
{
    /** @var CustomBillService */
    private $customBills;

    public function __construct(CustomBillService $customBills)
    {
        $this->customBills = $customBills;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomBill::class);

        $data = $this->customBills->paginate($request->only([
            'search',
            'status',
            'client_account_id',
            'bill_number',
            'date_from',
            'date_to',
            'sort_by',
            'sort_dir',
            'per_page',
            'page',
        ]));

        return response()->json($data);
    }

    public function store(CustomBillStoreRequest $request): JsonResponse
    {
        $this->authorize('create', CustomBill::class);

        $bill = $this->customBills->create(
            $request->headerPayload(),
            $request->itemsPayload(),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill), 201);
    }

    public function show(CustomBill $customBill): JsonResponse
    {
        $this->authorize('view', $customBill);

        return response()->json($this->customBills->toDetailArray($customBill));
    }

    public function update(CustomBillUpdateRequest $request, CustomBill $customBill): JsonResponse
    {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->updateHeader(
            $customBill,
            $request->validated(),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill));
    }

    public function destroy(CustomBill $customBill): JsonResponse
    {
        $this->authorize('delete', $customBill);

        $this->customBills->delete($customBill, request()->user());

        return response()->json(['message' => 'Custom bill deleted.']);
    }

    public function storeItem(CustomBillItemStoreRequest $request, CustomBill $customBill): JsonResponse
    {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->addItem(
            $customBill,
            $request->validated(),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill));
    }

    public function updateItem(
        CustomBillItemUpdateRequest $request,
        CustomBill $customBill,
        CustomBillItem $item
    ): JsonResponse {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->updateItem(
            $customBill,
            $item,
            $request->validated(),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill));
    }

    public function destroyItem(CustomBill $customBill, CustomBillItem $item): JsonResponse
    {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->deleteItem($customBill, $item, request()->user());

        return response()->json($this->customBills->toDetailArray($bill));
    }

    public function updateStatus(CustomBillStatusRequest $request, CustomBill $customBill): JsonResponse
    {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->updateStatus(
            $customBill,
            (string) $request->input('status'),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill));
    }

    public function draftInvoices(CustomBill $customBill): JsonResponse
    {
        $this->authorize('view', $customBill);

        return response()->json([
            'invoices' => $this->customBills->listDraftInvoicesForAccount($customBill),
        ]);
    }

    public function addToInvoice(CustomBillAddToInvoiceRequest $request, CustomBill $customBill): JsonResponse
    {
        $this->authorize('update', $customBill);

        $bill = $this->customBills->addToInvoice(
            $customBill,
            (int) $request->input('invoice_id'),
            $request->user()
        );

        return response()->json($this->customBills->toDetailArray($bill));
    }
}
