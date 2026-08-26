<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LeadBizyWebhookController extends Controller
{
    /** @var LeadService */
    private $leads;

    public function __construct(LeadService $leads)
    {
        $this->leads = $leads;
    }

    /**
     * @return JsonResponse|Response
     */
    public function handle(Request $request)
    {
        if ($request->isMethod('HEAD') || $request->isMethod('GET')) {
            return response('', 200);
        }

        $secret = trim((string) config('services.leads.bizy_webhook_secret', ''));
        if ($secret === '') {
            return response()->json([
                'message' => 'Bizy leads webhook secret is not configured.',
            ], 500);
        }

        if (! $this->secretMatches($request, $secret)) {
            Log::warning('leads.bizy_webhook.invalid_secret');

            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        try {
            $result = $this->leads->createFromBizyWebhook($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        $lead = $result['lead'];
        $payload = [
            'status' => $result['status'],
            'lead_id' => $lead !== null ? (int) $lead->id : null,
            'email' => $lead !== null ? (string) $lead->email : null,
            'referral' => Lead::REFERRAL_BIZY,
        ];

        if ($result['status'] === 'duplicate') {
            return response()->json($payload, 200);
        }

        return response()->json($payload, 201);
    }

    private function secretMatches(Request $request, string $secret): bool
    {
        $candidates = [
            (string) $request->header('X-Leads-Webhook-Secret', ''),
            (string) $request->header('X-Webhook-Secret', ''),
        ];

        $auth = trim((string) $request->header('Authorization', ''));
        if (stripos($auth, 'Bearer ') === 0) {
            $candidates[] = substr($auth, 7);
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && hash_equals($secret, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
