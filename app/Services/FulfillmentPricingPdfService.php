<?php

namespace App\Services;

use App\Models\ClientAccount;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class FulfillmentPricingPdfService
{
    /** @var ClientAccountService */
    protected $clientAccounts;

    public function __construct(ClientAccountService $clientAccounts)
    {
        $this->clientAccounts = $clientAccounts;
    }

    /**
     * Onboarding / portal PDF — fees only when fulfillment pricing is Approved.
     */
    public function download(ClientAccount $account): Response
    {
        $account->loadMissing('feeItems');
        $approved = $this->clientAccounts->normalizeFulfillmentPricingStatus(
            $account->fulfillment_pricing_status
        ) === ClientAccount::FULFILLMENT_PRICING_STATUS_APPROVED;

        $fees = $approved
            ? ($this->clientAccounts->feesPayloadForApi($account)['items'] ?? [])
            : [];

        return $this->streamPdf(
            $account,
            is_array($fees) ? $fees : [],
            $approved,
            'Quoted pricing has not been set for this account',
            'fulfillment-pricing-'.$this->safeAccountSlug($account).'.pdf'
        );
    }

    /**
     * Admin account Fees tab export — always includes all account fees.
     */
    public function downloadForAccount(ClientAccount $account): Response
    {
        $account->loadMissing('feeItems');
        $fees = $this->clientAccounts->feesPayloadForApi($account)['items'] ?? [];
        $fees = is_array($fees) ? $fees : [];

        return $this->streamPdf(
            $account,
            $fees,
            true,
            'No fees are configured for this account.',
            $this->safeAccountSlug($account).'-Pricing.pdf'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fees
     */
    private function streamPdf(
        ClientAccount $account,
        array $fees,
        bool $approved,
        string $emptyMessage,
        string $filename
    ): Response {
        $pdf = Pdf::loadView('pdf.fulfillment-pricing', [
            'title' => 'Save Rack Fulfillment Pricing',
            'accountName' => trim((string) ($account->company_name ?: $account->brand_name ?: 'Account')),
            'dateLabel' => Carbon::now()->format('F j, Y'),
            'approved' => $approved,
            'fees' => $fees,
            'emptyMessage' => $emptyMessage,
        ]);

        return $pdf->stream($filename);
    }

    private function safeAccountSlug(ClientAccount $account): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($account->company_name ?: 'account'));
        if ($safe === null || $safe === '' || $safe === '-') {
            return 'account';
        }

        return trim($safe, '-');
    }
}
