<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminBroadcastEmail;
use App\Services\AdminBroadcastEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBroadcastEmailController extends Controller
{
    /** @var AdminBroadcastEmailService */
    private $broadcasts;

    public function __construct(AdminBroadcastEmailService $broadcasts)
    {
        $this->broadcasts = $broadcasts;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AdminBroadcastEmail::class);

        $q = $request->query('q');
        $q = is_string($q) ? $q : null;
        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $paginator = $this->broadcasts->paginate($q, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(function (AdminBroadcastEmail $row) {
                return $this->broadcasts->toArray($row);
            })->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'from_options' => $this->broadcasts->fromOptions(),
        ]);
    }

    public function recipientCount(): JsonResponse
    {
        $this->authorize('create', AdminBroadcastEmail::class);

        return response()->json([
            'recipient_count' => $this->broadcasts->recipientCount(),
            'from_options' => $this->broadcasts->fromOptions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AdminBroadcastEmail::class);

        $fromAddresses = array_keys(config('crm.broadcast_from_options', []));

        $validated = $request->validate([
            'from_address' => ['required', 'string', 'max:255', Rule::in($fromAddresses)],
            'subject' => ['required', 'string', 'max:500'],
            'body_html' => ['required', 'string'],
        ]);

        $broadcast = $this->broadcasts->createAndSend($validated, $request->user());

        return response()->json([
            'email' => $this->broadcasts->toArray($broadcast),
            'recipient_count' => (int) $broadcast->recipient_count,
        ], 201);
    }

    public function show(AdminBroadcastEmail $adminBroadcastEmail): JsonResponse
    {
        $this->authorize('view', $adminBroadcastEmail);

        return response()->json($this->broadcasts->toArray($adminBroadcastEmail));
    }

    public function destroy(AdminBroadcastEmail $adminBroadcastEmail): JsonResponse
    {
        $this->authorize('delete', $adminBroadcastEmail);

        $this->broadcasts->delete($adminBroadcastEmail);

        return response()->json(['ok' => true]);
    }
}
