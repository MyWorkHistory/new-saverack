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

        return $this->streamPdf(
            $account,
            is_array($fees) ? $fees : [],
            $approved,
            'Quoted pricing has not been set for this account',
            'fulfillment-pricing-'.$this->safeAccountSlug($account).'.pdf'
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
     * Embed fee icons as small data URIs so DomPDF never fetches URLs.
     * Icons are best-effort: any failure (missing GD, bad file, unsupported
     * format) falls back to the letter placeholder instead of a 500.
     *
     * @param  list<array<string, mixed>>  $fees
     * @return list<array<string, mixed>>
     */
    private function addEmbeddedIcons(ClientAccount $account, array $fees): array
    {
        $gdAvailable = extension_loaded('gd');
        $disk = Storage::disk('public');

        $pathsById = $account->feeItems
            ->mapWithKeys(function ($fee) {
                return [(int) $fee->id => $fee->icon_path];
            });

        foreach ($fees as $index => $fee) {
            $fees[$index]['icon_data_uri'] = null;

            $id = (int) ($fee['id'] ?? 0);
            $path = $pathsById->get($id);
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

                // Without GD, DomPDF can only embed plain JPEGs reliably.
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

    private function safeAccountSlug(ClientAccount $account): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($account->company_name ?: 'account'));
        if ($safe === null || $safe === '' || $safe === '-') {
            return 'account';
        }

        return trim($safe, '-');
    }
}
