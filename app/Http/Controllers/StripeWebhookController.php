<?php

namespace App\Http\Controllers;

use App\Services\InvoiceService;
use App\Services\StripeInvoicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function handle(
        Request $request,
        StripeInvoicePaymentService $stripePayments,
        InvoiceService $invoiceService
    ): JsonResponse {
        $secret = trim((string) config('services.stripe.webhook_secret', ''));
        if ($secret === '') {
            return response()->json(['received' => false, 'message' => 'Stripe webhook secret is not configured.'], 500);
        }
        $signature = (string) $request->header('Stripe-Signature', '');
        $payload = (string) $request->getContent();

        try {
            $result = $stripePayments->handleWebhook($payload, $signature, $secret, $invoiceService);
            return response()->json(['received' => true] + $result);
        } catch (\Throwable $e) {
            return response()->json(['received' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
