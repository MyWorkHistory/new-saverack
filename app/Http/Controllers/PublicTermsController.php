<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Services\FulfillmentAgreementPdfService;
use App\Services\TermsOfServiceService;
use App\Support\HtmlSanitizer;
use Illuminate\View\View;

class PublicTermsController extends Controller
{
    /** @var TermsOfServiceService */
    private $terms;

    /** @var FulfillmentAgreementPdfService */
    private $agreementPdfs;

    public function __construct(TermsOfServiceService $terms, FulfillmentAgreementPdfService $agreementPdfs)
    {
        $this->terms = $terms;
        $this->agreementPdfs = $agreementPdfs;
    }

    public function global(): View
    {
        return view('public.terms', [
            'title' => 'Terms of Service',
            'body_html' => HtmlSanitizer::sanitize($this->terms->globalBody()),
        ]);
    }

    public function account(ClientAccount $clientAccount)
    {
        // Once both parties have signed, the public link shows the signed agreement PDF.
        if ($clientAccount->fulfillment_agreement_client_signed_at !== null
            && $clientAccount->fulfillment_agreement_staff_signed_at !== null) {
            return $this->agreementPdfs->signedPdfResponse($clientAccount);
        }

        return view('public.terms', [
            'title' => 'Terms of Service',
            'body_html' => HtmlSanitizer::sanitize($this->terms->effectiveBodyForAccount($clientAccount)),
        ]);
    }
}
