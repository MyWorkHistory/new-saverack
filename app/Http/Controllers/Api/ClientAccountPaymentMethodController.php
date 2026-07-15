<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\PaymentMethodLink;
use App\Services\AccountPaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientAccountPaymentMethodController extends Controller
{
    /** @var AccountPaymentMethodService */
    private $paymentMethods;

    public function __construct(AccountPaymentMethodService $paymentMethods)
    {
        $this->paymentMethods = $paymentMethods;
    }

    public function createLink(Request $request, ClientAccount $client_account): JsonResponse
    {
        $this->authorize('update', $client_account);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in([
                PaymentMethodLink::METHOD_CREDIT_CARD,
                PaymentMethodLink::METHOD_ACH,
            ])],
            'replace_payment_method_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $link = $this->paymentMethods->createLink(
                $client_account,
                (string) $validated['method'],
                $request->user(),
                $validated['replace_payment_method_id'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not create payment method link.',
            ], 422);
        }

        return response()->json($link, 201);
    }

    public function destroy(ClientAccount $client_account, string $paymentMethodId): JsonResponse
    {
        $this->authorize('update', $client_account);

        try {
            $this->paymentMethods->detachPaymentMethod($client_account, $paymentMethodId);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not remove payment method.',
            ], 422);
        }

        return response()->json(null, 204);
    }

    public function unlock(Request $request, ClientAccount $client_account, string $paymentMethodId): JsonResponse
    {
        $this->authorize('view', $client_account);

        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:32'],
        ]);

        if (! $this->paymentMethods->pinMatches((string) $validated['pin'])) {
            return response()->json([
                'message' => 'Incorrect PIN.',
            ], 403);
        }

        try {
            $detail = $this->paymentMethods->paymentMethodDetail($client_account, $paymentMethodId);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not load payment method.',
            ], 422);
        }

        return response()->json(['payment_method' => $detail]);
    }
}
