<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\PortalOnboardingService;
use App\Services\PortalOnboardingStripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PortalOnboardingController extends Controller
{
    /** @var PortalOnboardingService */
    protected $onboarding;

    /** @var PortalOnboardingStripeService */
    protected $stripeOnboarding;

    public function __construct(
        PortalOnboardingService $onboarding,
        PortalOnboardingStripeService $stripeOnboarding
    ) {
        $this->onboarding = $onboarding;
        $this->stripeOnboarding = $stripeOnboarding;
    }

    public function show(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function saveManualBilling(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $account = $this->onboarding->completeManualBilling($account);

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function startStripeCheckout(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in([
                PortalOnboardingService::BILLING_METHOD_CREDIT_CARD,
                PortalOnboardingService::BILLING_METHOD_ACH,
            ])],
        ]);

        $method = (string) $validated['method'];

        try {
            $checkoutUrl = $this->stripeOnboarding->createCheckoutSession($account, $user, $method);
            $account = $this->onboarding->markBillingStripeCheckoutStarted($account, $method);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'onboarding' => $this->onboarding->buildOnboardingPayload($user, $account),
        ]);
    }

    /**
     * @return array{0: User, 1: ClientAccount}
     */
    private function resolvePortalUserAndAccount(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) ($user->client_account_id ?? 0);
        if ($clientAccountId <= 0) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Portal onboarding is only available for client portal users.'],
            ]);
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);

        return [$user, $account];
    }
}
