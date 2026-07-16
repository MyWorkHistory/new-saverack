<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AccountPaymentMethodService;
use App\Services\ClientBrandLogoService;
use App\Services\FulfillmentAgreementPdfService;
use App\Services\FulfillmentAgreementService;
use App\Services\PortalOnboardingService;
use App\Services\PortalOnboardingStripeService;
use App\Support\PortalOnboardingSectionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PortalOnboardingController extends Controller
{
    /** @var PortalOnboardingService */
    protected $onboarding;

    /** @var PortalOnboardingStripeService */
    protected $stripeOnboarding;

    /** @var ClientBrandLogoService */
    protected $brandLogos;

    /** @var AccountPaymentMethodService */
    protected $paymentMethods;

    /** @var FulfillmentAgreementService */
    protected $agreements;

    /** @var FulfillmentAgreementPdfService */
    protected $agreementPdfs;

    public function __construct(
        PortalOnboardingService $onboarding,
        PortalOnboardingStripeService $stripeOnboarding,
        ClientBrandLogoService $brandLogos,
        AccountPaymentMethodService $paymentMethods,
        FulfillmentAgreementService $agreements,
        FulfillmentAgreementPdfService $agreementPdfs
    ) {
        $this->onboarding = $onboarding;
        $this->stripeOnboarding = $stripeOnboarding;
        $this->brandLogos = $brandLogos;
        $this->paymentMethods = $paymentMethods;
        $this->agreements = $agreements;
        $this->agreementPdfs = $agreementPdfs;
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

    public function createPaymentMethodLink(Request $request): JsonResponse
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
        $link = $this->paymentMethods->createLink($account, $method, $user);
        $account = $this->onboarding->markBillingStripeCheckoutStarted($account, $method);

        return response()->json([
            'url' => $link['url'],
            'token' => $link['token'] ?? null,
            'method' => $link['method'] ?? $method,
            'onboarding' => $this->onboarding->buildOnboardingPayload($user, $account),
        ]);
    }

    public function savePreferences(Request $request, string $section): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        if (! PortalOnboardingSectionRegistry::isValidSectionId($section)) {
            throw ValidationException::withMessages([
                'section' => ['Unknown onboarding section.'],
            ]);
        }

        $input = $request->except(['_token']);
        try {
            $account = $this->onboarding->savePreferenceSection($account, $section, $input);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'section' => [$e->getMessage()],
            ]);
        }

        if (! $this->onboarding->isPreferenceSectionComplete($account, $section)) {
            throw ValidationException::withMessages([
                'section' => ['Please complete all required fields for this section.'],
            ]);
        }

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function uploadBrandLogo(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $request->validate([
            'logo' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $this->brandLogos->replaceForAccount($account, $request->file('logo'));
        $account = $account->fresh();

        return response()->json([
            'brand_logo_url' => $this->brandLogos->publicUrl($account->brand_logo_path),
            'onboarding' => $this->onboarding->buildOnboardingPayload($user, $account),
        ]);
    }

    public function acceptFulfillmentAgreement(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        try {
            $this->onboarding->acceptFulfillmentAgreement($account);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function downloadFulfillmentAgreementPdf(Request $request): Response
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        return $this->agreementPdfs->downloadBlank($account);
    }

    public function uploadFulfillmentAgreement(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'company' => ['nullable', 'string', 'max:255'],
            'rep_name' => ['nullable', 'string', 'max:255'],
            'signed_at' => ['nullable', 'date'],
        ]);

        $account = $this->agreements->completeUpload(
            $account,
            $request->file('file'),
            [
                'company' => $validated['company'] ?? null,
                'rep_name' => $validated['rep_name'] ?? null,
                'signed_at' => $validated['signed_at'] ?? null,
            ]
        );

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function esignFulfillmentAgreement(Request $request): JsonResponse
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'rep_name' => ['required', 'string', 'max:255'],
            'signed_at' => ['nullable', 'date'],
            'signature_style' => ['required', 'string', 'max:64'],
            'signature_text' => ['required', 'string', 'max:255'],
            'signature_image' => ['required', 'string'],
        ]);

        $account = $this->agreements->completeEsign($account, $validated);

        return response()->json($this->onboarding->buildOnboardingPayload($user, $account));
    }

    public function downloadSignedFulfillmentAgreement(Request $request): Response
    {
        [$user, $account] = $this->resolvePortalUserAndAccount($request);
        Gate::forUser($request->user())->authorize('view', $account);

        return $this->agreementPdfs->signedPdfResponse($account);
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
