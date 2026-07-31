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
            ? ($this->clientAccounts->feesPayloadForApi($account, false, true)['items'] ?? [])
            : [];

        $pathsById = $account->feeItems->mapWithKeys(function ($fee) {
            return [(int) $fee->id => $fee->icon_path];
        });

        return $this->streamPdf(
            trim((string) ($account->company_name ?: $account->brand_name ?: 'Account')),
            is_array($fees) ? $fees : [],
            $approved,
            'Quoted pricing has not been set for this account',
            'fulfillment-pricing-'.$this->safeSlug((string) ($account->company_name ?: 'account')).'.pdf',
            $pathsById
        );
    }

    /**
     * Admin account Fees tab export — client-visible fees only (no Postage, no cost).
     */
    public function downloadForAccount(ClientAccount $account): Response
    {
        $account->loadMissing('feeItems');
        $fees = $this->clientAccounts->feesPayloadForApi($account, false, true)['items'] ?? [];
        $fees = is_array($fees) ? $fees : [];

        $pathsById = $account->feeItems->mapWithKeys(function ($fee) {
            return [(int) $fee->id => $fee->icon_path];
        });

        return $this->streamPdf(
            trim((string) ($account->company_name ?: $account->brand_name ?: 'Account')),
            $fees,
            true,
            'No fees are configured for this account.',
            $this->safeSlug((string) ($account->company_name ?: 'account')).'-Pricing.pdf',
            $pathsById
        );
    }

    /**
     * Lead Fees tab export — same schedule PDF as account (no cost).
     *
     * @param  list<array<string, mixed>>  $fees
     */
    public function downloadForLead(\App\Models\Lead $lead, array $fees): Response
    {
        $lead->loadMissing(['feeItems.pricingTemplate']);
        $pathsById = $lead->feeItems->mapWithKeys(function ($fee) {
            $path = $fee->icon_path;
            if ((! is_string($path) || trim($path) === '') && $fee->pricingTemplate !== null) {
                $path = $fee->pricingTemplate->icon_path;
            }

            return [(int) $fee->id => $path];
        });

        $name = trim((string) ($lead->company_name ?: 'Lead'));

        return $this->streamPdf(
            $name !== '' ? $name : 'Lead',
            $fees,
            true,
            'No fee schedule loaded for this lead.',
            $this->safeSlug($name !== '' ? $name : 'lead').'-Pricing.pdf',
            $pathsById
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fees
     * @param  \Illuminate\Support\Collection<int, string|null>|array<int, string|null>  $pathsById
     */
    private function streamPdf(
        string $accountName,
        array $fees,
        bool $approved,
        string $emptyMessage,
        string $filename,
        $pathsById
    ): Response {
        $fees = $this->addEmbeddedIcons($pathsById, $fees);

        $pdf = Pdf::loadView('pdf.fulfillment-pricing', [
            'title' => 'Save Rack Fulfillment Pricing',
            'accountName' => $accountName,
            'dateLabel' => Carbon::now()->format('F j, Y'),
            'approved' => $approved,
            'fees' => $fees,
            'emptyMessage' => $emptyMessage,
        ]);

        return $pdf->stream($filename);
    }

    /**
     * Embed fee icons as small data URIs so DomPDF never fetches URLs.
     *
     * @param  \Illuminate\Support\Collection<int, string|null>|array<int, string|null>  $pathsById
     * @param  list<array<string, mixed>>  $fees
     * @return list<array<string, mixed>>
     */
    private function addEmbeddedIcons($pathsById, array $fees): array
    {
        $gdAvailable = extension_loaded('gd');
        $disk = Storage::disk('public');
        $paths = $pathsById instanceof \Illuminate\Support\Collection
            ? $pathsById
            : collect($pathsById);

        foreach ($fees as $index => $fee) {
            $fees[$index]['icon_data_uri'] = null;

            $id = (int) ($fee['id'] ?? 0);
            $path = $paths->get($id);
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                $contents = $disk->get($path);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                if ($gdAvailable) {
                    $normalized = $this->normalizeIconToPng($contents);
                    if ($normalized !== null) {
                        $fees[$index]['icon_data_uri'] = 'data:image/png;base64,'.base64_encode($normalized);
                    }
                    continue;
                }

                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg'], true)) {
                    $fees[$index]['icon_data_uri'] = 'data:image/jpeg;base64,'.base64_encode($contents);
                }
            } catch (\Throwable $e) {
                // Icon is decorative; never fail the PDF over it.
            }
        }

        return $fees;
    }

    /**
     * Decode any supported image and re-encode as a small PNG (max 96px)
     * so the PDF stays light and DomPDF gets a format it always handles.
     */
    private function normalizeIconToPng(string $contents): ?string
    {
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            imagedestroy($source);

            return null;
        }

        $max = 96;
        $scale = min(1, $max / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $ok = imagepng($target);
        $png = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $ok && is_string($png) && $png !== '' ? $png : null;
    }

    private function safeSlug(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        if ($safe === null || $safe === '' || $safe === '-') {
            return 'account';
        }

        return trim($safe, '-');
    }
}
