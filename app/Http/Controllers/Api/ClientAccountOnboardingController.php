<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ClientBrandLogoService;
use App\Services\PortalOnboardingService;
use App\Services\PortalOnboardingStripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ClientAccountOnboardingController extends Controller
{
    /** @var PortalOnboardingService */
    protected $onboarding;

    /** @var PortalOnboardingStripeService */
    protected $stripeOnboarding;

    /** @var ClientBrandLogoService */
    protected $brandLogos;

    public function __construct(
        PortalOnboardingService $onboarding,
        PortalOnboardingStripeService $stripeOnboarding,
        ClientBrandLogoService $brandLogos
    ) {
        $this->onboarding = $onboarding;
        $this->stripeOnboarding = $stripeOnboarding;
        $this->brandLogos = $brandLogos;
    }

    public function show(ClientAccount $client_account): JsonResponse
    {
        Gate::authorize('view', $client_account);

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }

    public function updateProfile(Request $request, ClientAccount $client_account): JsonResponse
    {
        Gate::authorize('update', $client_account);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->onboarding->resolvePrimaryPortalUser($client_account)->id),
            ],
            'company_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:64'],
            'zip' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $this->onboarding->resolvePrimaryPortalUser($client_account);

        DB::transaction(function () use ($user, $client_account, $validated) {
            $this->onboarding->updateAccountProfile($user, $client_account, $validated);
        });

        $client_account->refresh();
        $user->refresh();

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }

    public function savePreferences(Request $request, ClientAccount $client_account, string $section): JsonResponse
    {
        Gate::authorize('update', $client_account);

        if (! \App\Support\PortalOnboardingSectionRegistry::isValidSectionId($section)) {
            throw ValidationException::withMessages([
                'section' => ['Unknown onboarding section.'],
            ]);
        }

        $input = $request->except(['_token']);
        try {
            $client_account = $this->onboarding->savePreferenceSection($client_account, $section, $input);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'section' => [$e->getMessage()],
            ]);
        }

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }

    public function uploadBrandLogo(Request $request, ClientAccount $client_account): JsonResponse
    {
        Gate::authorize('update', $client_account);

        $request->validate([
            'logo' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $this->brandLogos->replaceForAccount($client_account, $request->file('logo'));
        $client_account = $client_account->fresh();

        return response()->json([
            'brand_logo_url' => $this->brandLogos->publicUrl($client_account->brand_logo_path),
            'onboarding' => $this->onboarding->buildAdminOnboardingPayload($client_account),
        ]);
    }

    public function saveBilling(Request $request, ClientAccount $client_account): JsonResponse
    {
        Gate::authorize('update', $client_account);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in([
                PortalOnboardingService::BILLING_METHOD_CREDIT_CARD,
                PortalOnboardingService::BILLING_METHOD_ACH,
                PortalOnboardingService::BILLING_METHOD_MANUAL,
            ])],
            'use_stripe_checkout' => ['sometimes', 'boolean'],
            'return_context' => ['sometimes', 'string', Rule::in(['account_billing'])],
        ]);

        $method = (string) $validated['method'];
        $useStripe = (bool) ($validated['use_stripe_checkout'] ?? false);

        if ($useStripe && $method !== PortalOnboardingService::BILLING_METHOD_MANUAL) {
            $user = $this->onboarding->resolvePrimaryPortalUser($client_account);
            $successUrl = null;
            $cancelUrl = null;
            if (($validated['return_context'] ?? '') === 'account_billing') {
                $successUrl = $this->onboarding->accountBillingReturnUrl($client_account->id, 'success');
                $cancelUrl = $this->onboarding->accountBillingReturnUrl($client_account->id, 'cancel');
            }
            try {
                $checkoutUrl = $this->stripeOnboarding->createCheckoutSession(
                    $client_account,
                    $user,
                    $method,
                    $successUrl,
                    $cancelUrl
                );
                $client_account = $this->onboarding->markBillingStripeCheckoutStarted($client_account, $method);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            return response()->json([
                'checkout_url' => $checkoutUrl,
                'onboarding' => $this->onboarding->buildAdminOnboardingPayload($client_account),
            ]);
        }

        $client_account = $this->onboarding->applyAdminBillingMethod($client_account, $method);

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }

    public function updateTaskVerification(Request $request, ClientAccount $client_account, string $task): JsonResponse
    {
        Gate::authorize('update', $client_account);

        $validated = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        $verified = (bool) $validated['verified'];
        $adminUser = $request->user();

        try {
            $client_account = $this->onboarding->setTaskVerified(
                $client_account,
                $task,
                $verified,
                $adminUser !== null ? (int) $adminUser->id : null
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'task' => [$e->getMessage()],
            ]);
        }

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }

    public function updateTaskFieldVerification(
        Request $request,
        ClientAccount $client_account,
        string $task,
        string $field
    ): JsonResponse {
        Gate::authorize('update', $client_account);

        $validated = $request->validate([
            'checked' => ['required', 'boolean'],
        ]);

        $checked = (bool) $validated['checked'];
        $adminUser = $request->user();

        try {
            $client_account = $this->onboarding->setTaskFieldVerified(
                $client_account,
                $task,
                $field,
                $checked,
                $adminUser !== null ? (int) $adminUser->id : null
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'field' => [$e->getMessage()],
            ]);
        }

        return response()->json($this->onboarding->buildAdminOnboardingPayload($client_account));
    }
}
