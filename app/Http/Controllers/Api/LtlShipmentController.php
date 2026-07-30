<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\LtlShipment;
use App\Models\LtlShipmentComment;
use App\Models\LtlShipmentPallet;
use App\Services\LtlShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LtlShipmentController extends Controller
{
    /** @var LtlShipmentService */
    private $ltl;

    public function __construct(LtlShipmentService $ltl)
    {
        $this->ltl = $ltl;
    }

    public function meta(): JsonResponse
    {
        $this->authorize('viewAny', LtlShipment::class);

        return response()->json([
            'directions' => config('ltl.directions'),
            'statuses' => config('ltl.statuses'),
            'load_requirements' => config('ltl.load_requirements'),
            'pickup_types' => config('ltl.pickup_types'),
            'services' => config('ltl.services'),
            'time_modes' => config('ltl.time_modes'),
            'facility' => config('ltl.facility'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LtlShipment::class);

        $query = LtlShipment::query()->with(['clientAccount', 'pallets']);
        $user = $request->user();
        if ($user !== null && (int) ($user->client_account_id ?? 0) > 0) {
            $query->where('client_account_id', (int) $user->client_account_id);
        } elseif ($request->filled('client_account_id')) {
            $query->where('client_account_id', (int) $request->input('client_account_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($q) {
                $inner->where('number', 'like', '%'.$q.'%')
                    ->orWhere('company_name', 'like', '%'.$q.'%')
                    ->orWhere('quote_carrier', 'like', '%'.$q.'%')
                    ->orWhereHas('clientAccount', function ($a) use ($q) {
                        $a->where('company_name', 'like', '%'.$q.'%');
                    });
            });
        }

        $rows = $query->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => collect($rows->items())->map(function (LtlShipment $s) {
                return $this->ltl->toApiArray($s, false);
            })->values()->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isPortal = $user !== null && (int) ($user->client_account_id ?? 0) > 0;

        $validated = $request->validate([
            'client_account_id' => [$isPortal ? 'nullable' : 'required', 'integer', 'exists:client_accounts,id'],
            'direction' => ['required', 'string', Rule::in(LtlShipment::DIRECTIONS)],
            'company_name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:64'],
            'zip' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
        ]);

        $accountId = $isPortal
            ? (int) $user->client_account_id
            : (int) $validated['client_account_id'];
        $account = ClientAccount::query()->findOrFail($accountId);
        $this->authorize('create', [LtlShipment::class, $account]);

        $shipment = $this->ltl->create($account, $validated, $user);

        return response()->json([
            'message' => 'LTL created.',
            'shipment' => $this->ltl->toApiArray($shipment),
        ], 201);
    }

    public function show(LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('view', $ltlShipment);

        return response()->json([
            'shipment' => $this->ltl->toApiArray($ltlShipment),
        ]);
    }

    public function update(Request $request, LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('update', $ltlShipment);

        $validated = $request->validate([
            'direction' => ['sometimes', 'string', Rule::in(LtlShipment::DIRECTIONS)],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:64'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'time_mode' => ['sometimes', 'nullable', 'string', Rule::in([LtlShipment::TIME_ASAP, LtlShipment::TIME_SPECIFIC])],
            'time_from' => ['sometimes', 'nullable', 'date'],
            'time_to' => ['sometimes', 'nullable', 'date'],
            'load_requirement' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickup_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'quote_amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'quote_carrier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quote_transit_time' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quote_service' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $isPortal = $user !== null && (int) ($user->client_account_id ?? 0) > 0;
        if ($isPortal) {
            unset(
                $validated['quote_amount_cents'],
                $validated['quote_carrier'],
                $validated['quote_transit_time'],
                $validated['quote_service'],
                $validated['tracking_number']
            );
        }

        $shipment = $this->ltl->updateDetails($ltlShipment, $validated);

        return response()->json([
            'message' => 'LTL updated.',
            'shipment' => $this->ltl->toApiArray($shipment),
        ]);
    }

    public function requestQuote(LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        $shipment = $this->ltl->requestQuote($ltlShipment);

        return response()->json([
            'message' => 'Quote requested.',
            'shipment' => $this->ltl->toApiArray($shipment),
        ]);
    }

    public function updateStatus(Request $request, LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('changeStatus', $ltlShipment);
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(LtlShipment::STATUSES)],
        ]);
        $shipment = $this->ltl->updateStatus($ltlShipment, (string) $validated['status']);

        return response()->json([
            'message' => 'Status updated.',
            'shipment' => $this->ltl->toApiArray($shipment),
        ]);
    }

    public function storePallet(Request $request, LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        $validated = $request->validate([
            'commodity' => ['nullable', 'string', 'max:255'],
            'length_in' => ['nullable', 'numeric', 'min:0'],
            'width_in' => ['nullable', 'numeric', 'min:0'],
            'height_in' => ['nullable', 'numeric', 'min:0'],
            'weight_lbs' => ['nullable', 'numeric', 'min:0'],
        ]);
        $pallet = $this->ltl->addPallet($ltlShipment, $validated);

        return response()->json([
            'message' => 'Pallet added.',
            'pallet' => $pallet,
            'shipment' => $this->ltl->toApiArray($ltlShipment->fresh(['clientAccount', 'pallets'])),
        ], 201);
    }

    public function updatePallet(Request $request, LtlShipment $ltlShipment, LtlShipmentPallet $pallet): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        if ((int) $pallet->ltl_shipment_id !== (int) $ltlShipment->id) {
            abort(404);
        }
        $validated = $request->validate([
            'commodity' => ['sometimes', 'nullable', 'string', 'max:255'],
            'length_in' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'width_in' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'height_in' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight_lbs' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $this->ltl->updatePallet($pallet, $validated);

        return response()->json([
            'message' => 'Pallet updated.',
            'shipment' => $this->ltl->toApiArray($ltlShipment->fresh(['clientAccount', 'pallets'])),
        ]);
    }

    public function destroyPallet(LtlShipment $ltlShipment, LtlShipmentPallet $pallet): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        if ((int) $pallet->ltl_shipment_id !== (int) $ltlShipment->id) {
            abort(404);
        }
        $this->ltl->deletePallet($pallet);

        return response()->json([
            'message' => 'Pallet removed.',
            'shipment' => $this->ltl->toApiArray($ltlShipment->fresh(['clientAccount', 'pallets'])),
        ]);
    }

    public function storeComment(Request $request, LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        $comment = $this->ltl->addComment($ltlShipment, $user, trim((string) $validated['body']));

        return response()->json([
            'message' => 'Note added.',
            'comment' => $this->ltl->commentToApiArray($comment),
            'shipment' => $this->ltl->toApiArray($ltlShipment->fresh()),
        ], 201);
    }

    public function destroyComment(Request $request, LtlShipment $ltlShipment, LtlShipmentComment $comment): JsonResponse
    {
        $this->authorize('update', $ltlShipment);
        if ((int) $comment->ltl_shipment_id !== (int) $ltlShipment->id) {
            abort(404);
        }
        $user = $request->user();
        $isPortal = $user !== null && (int) ($user->client_account_id ?? 0) > 0;
        if ($isPortal && (int) $comment->user_id !== (int) $user->id) {
            abort(403);
        }
        $comment->delete();

        return response()->json([
            'message' => 'Note deleted.',
            'shipment' => $this->ltl->toApiArray($ltlShipment->fresh()),
        ]);
    }

    public function destroy(LtlShipment $ltlShipment): JsonResponse
    {
        $this->authorize('delete', $ltlShipment);
        $ltlShipment->delete();

        return response()->json(['message' => 'LTL deleted.']);
    }
}
