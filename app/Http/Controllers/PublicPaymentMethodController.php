<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethodLink;
use App\Services\AccountPaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicPaymentMethodController extends Controller
{
    /** @var AccountPaymentMethodService */
    private $paymentMethods;

    public function __construct(AccountPaymentMethodService $paymentMethods)
    {
        $this->paymentMethods = $paymentMethods;
    }

    public function show(string $token): View
    {
        $link = $this->paymentMethods->findUsableLink($token);
        if (! $link instanceof PaymentMethodLink) {
            abort(404);
        }

        $isAch = $link->method === PaymentMethodLink::METHOD_ACH;

        return view($isAch ? 'public.payment-method.ach' : 'public.payment-method.card', [
            'token' => $link->token,
            'method' => $link->method,
            'company_name' => (string) ($link->clientAccount->company_name ?? ''),
            'setup_intent_url' => url('/api/public/payment-method/'.$link->token.'/setup-intent'),
            'complete_url' => url('/api/public/payment-method/'.$link->token.'/complete'),
            'thanks_url' => url('/payment-method/'.$link->token.'/thanks'),
            'cc_terms_html' => $this->creditCardTermsHtml(),
            'ach_terms_html' => $this->achTermsHtml(),
        ]);
    }

    public function thanks(string $token): View
    {
        $link = PaymentMethodLink::query()->where('token', $token)->first();
        if (! $link instanceof PaymentMethodLink) {
            abort(404);
        }

        return view('public.payment-method.thanks', [
            'company_name' => (string) optional($link->clientAccount)->company_name,
        ]);
    }

    public function setupIntent(string $token): JsonResponse
    {
        $link = $this->paymentMethods->findUsableLink($token);
        if (! $link instanceof PaymentMethodLink) {
            return response()->json(['message' => 'This payment link is invalid or has expired.'], 404);
        }

        try {
            $payload = $this->paymentMethods->createSetupIntentForLink($link);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not start payment setup.',
            ], 422);
        }

        return response()->json($payload);
    }

    public function complete(Request $request, string $token): JsonResponse
    {
        $link = $this->paymentMethods->findUsableLink($token);
        if (! $link instanceof PaymentMethodLink) {
            // Already consumed is OK for retries after success.
            $existing = PaymentMethodLink::query()->where('token', $token)->first();
            if ($existing instanceof PaymentMethodLink && $existing->isConsumed()) {
                return response()->json([
                    'ok' => true,
                    'thanks_url' => url('/payment-method/'.$token.'/thanks'),
                ]);
            }

            return response()->json(['message' => 'This payment link is invalid or has expired.'], 404);
        }

        $validated = $request->validate([
            'payment_method_id' => ['nullable', 'string', 'max:64'],
        ]);

        $this->paymentMethods->markLinkConsumed(
            $link,
            $validated['payment_method_id'] ?? null
        );

        return response()->json([
            'ok' => true,
            'thanks_url' => url('/payment-method/'.$token.'/thanks'),
        ]);
    }

    private function creditCardTermsHtml(): string
    {
        return <<<'HTML'
<h2>Credit Card Authorization Agreement</h2>
<p>I authorize Save Rack LLC ("Company") to charge the credit card listed above for all amounts due, including but not limited to fulfillment services, storage fees, shipping charges, postage, product costs, project fees, late fees, and any other charges incurred under my service agreement with Save Rack LLC.</p>
<p>I understand that charges may be processed on or after the due date of invoices issued by Save Rack LLC and that this authorization shall remain in effect until revoked in writing by either party. Any revocation of this authorization shall not relieve me of responsibility for any outstanding balances owed to Save Rack LLC.</p>
<p>I agree to notify Save Rack LLC promptly of any changes to my billing information, including card number, expiration date, billing address, or account status.</p>
<p>I represent and warrant that I am the authorized cardholder or an authorized signer on the account and have full authority to enter into this agreement.</p>
<p>I agree that all charges processed by Save Rack LLC pursuant to this authorization are for legitimate business services provided under our agreement. In the event of any billing questions or disputes, I agree to first contact Save Rack LLC and make a good faith effort to resolve the matter directly before initiating a chargeback or dispute with my card issuer.</p>
<p>I acknowledge and agree that a credit card processing fee of 3.5% will be added to each transaction processed by credit card, where permitted by applicable law.</p>
<p>This authorization shall remain in effect until terminated by written notice from the cardholder, provided that such termination shall not affect charges incurred prior to the effective date of termination.</p>
HTML;
    }

    private function achTermsHtml(): string
    {
        return <<<'HTML'
<h2>ACH Debit Authorization Agreement</h2>
<p>I authorize Save Rack LLC ("Company") to initiate ACH debit entries to the bank account listed above for all amounts due, including but not limited to fulfillment services, storage fees, shipping charges, postage, product costs, project fees, late fees, and any other charges incurred under my service agreement with Save Rack LLC.</p>
<p>I understand that debits may be processed on or after the due date of invoices issued by Save Rack LLC and that this authorization shall remain in effect until revoked in writing by either party. Any revocation of this authorization shall not relieve me of responsibility for any outstanding balances owed to Save Rack LLC.</p>
<p>I agree to notify Save Rack LLC promptly of any changes to my billing or bank account information, including routing number, account number, billing address, or account status.</p>
<p>I represent and warrant that I am an authorized signer on the bank account and have full authority to enter into this agreement.</p>
<p>I agree that all charges processed by Save Rack LLC pursuant to this authorization are for legitimate business services provided under our agreement. In the event of any billing questions or disputes, I agree to first contact Save Rack LLC and make a good faith effort to resolve the matter directly.</p>
<p>This authorization shall remain in effect until terminated by written notice from the account holder, provided that such termination shall not affect charges incurred prior to the effective date of termination.</p>
HTML;
    }
}
