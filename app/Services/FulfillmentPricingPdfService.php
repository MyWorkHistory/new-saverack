<?php

namespace App\Services;

use App\Models\ClientAccount;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
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
        $fees = $this->addEmbeddedIcons($account, $fees);

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

    /**
     * Embed fee icons so DomPDF does not depend on public URLs or remote-image access.
     *
     * @param  list<array<string, mixed>>  $fees
     * @return list<array<string, mixed>>
     */
    private function addEmbeddedIcons(ClientAccount $account, array $fees): array
    {
        $pathsById = $account->feeItems
            ->mapWithKeys(function ($fee) {
                return [(int) $fee->id => $fee->icon_path];
            });

        foreach ($fees as $index => $fee) {
            $id = (int) ($fee['id'] ?? 0);
            $path = $pathsById->get($id);
            if (! is_string($path) || trim($path) === '') {
                $fees[$index]['icon_data_uri'] = null;
                continue;
            }

            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                $fees[$index]['icon_data_uri'] = null;
                continue;
            }

            $contents = $disk->get($path);
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $mime = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ][$extension] ?? 'image/png';

            $fees[$index]['icon_data_uri'] = 'data:'.$mime.';base64,'.base64_encode($contents);
        }

        return $fees;
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
