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

    public function download(ClientAccount $account): Response
    {
        $account->loadMissing('feeItems');
        $approved = $this->clientAccounts->normalizeFulfillmentPricingStatus(
            $account->fulfillment_pricing_status
        ) === ClientAccount::FULFILLMENT_PRICING_STATUS_APPROVED;

        $fees = $approved
            ? ($this->clientAccounts->feesPayloadForApi($account)['items'] ?? [])
            : [];

        $pdf = Pdf::loadView('pdf.fulfillment-pricing', [
            'accountName' => trim((string) ($account->company_name ?: $account->brand_name ?: 'Account')),
            'dateLabel' => Carbon::now()->format('F j, Y'),
            'approved' => $approved,
            'fees' => is_array($fees) ? $fees : [],
            'emptyMessage' => 'Quoted pricing has not been set for this account',
        ]);

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($account->company_name ?: 'account'));
        if ($safe === null || $safe === '' || $safe === '-') {
            $safe = 'account';
        }

        return $pdf->stream('fulfillment-pricing-'.$safe.'.pdf');
    }
}
