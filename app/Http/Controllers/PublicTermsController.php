<?php

namespace App\Http\Controllers;

use App\Models\ClientAccount;
use App\Services\TermsOfServiceService;
use App\Support\HtmlSanitizer;
use Illuminate\View\View;

class PublicTermsController extends Controller
{
    /** @var TermsOfServiceService */
    private $terms;

    public function __construct(TermsOfServiceService $terms)
    {
        $this->terms = $terms;
    }

    public function global(): View
    {
        return view('public.terms', [
            'title' => 'Terms of Service',
            'body_html' => HtmlSanitizer::sanitize($this->terms->globalBody()),
        ]);
    }

    public function account(ClientAccount $clientAccount): View
    {
        return view('public.terms', [
            'title' => 'Terms of Service',
            'body_html' => HtmlSanitizer::sanitize($this->terms->effectiveBodyForAccount($clientAccount)),
        ]);
    }
}
