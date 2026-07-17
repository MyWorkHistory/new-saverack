<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Support\CrmUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FulfillmentAgreementService
{
    /** @var FulfillmentAgreementPdfService */
    protected $pdf;

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(
        FulfillmentAgreementPdfService $pdf,
        SlackDeliveryService $slack
    ) {
        $this->pdf = $pdf;
        $this->slack = $slack;
    }

    /**
     * @param  array{company: string, rep_name: string, signed_at?: string|null, signature_style: string, signature_text: string, signature_image: string}  $data
     */
    public function completeEsign(ClientAccount $account, array $data): ClientAccount
    {
        if ($account->fulfillment_agreement_accepted_at !== null) {
            throw ValidationException::withMessages([
                'agreement' => ['The fulfillment agreement has already been completed.'],
            ]);
        }

        $signedAt = $this->parseSignedAt($data['signed_at'] ?? null) ?: now();
        $imagePath = $this->pdf->storeSignatureImage($account, 'client', (string) $data['signature_image']);
        $meta = [
            'style' => (string) $data['signature_style'],
            'text' => (string) $data['signature_text'],
        ];
        if ($imagePath !== null) {
            $meta['image_path'] = $imagePath;
        }

        $account->fulfillment_agreement_method = FulfillmentAgreementPdfService::METHOD_ESIGN;
        $account->fulfillment_agreement_company = trim((string) $data['company']);
        $account->fulfillment_agreement_rep_name = trim((string) $data['rep_name']);
        $account->fulfillment_agreement_client_signed_at = $signedAt;
        $account->fulfillment_agreement_client_signature = json_encode($meta);
        $account->fulfillment_agreement_accepted_at = now();
        $account->save();

        $account = $this->pdf->buildAndStoreSigned($account);
        $this->notifySlackAgreementSigned($account);

        return $account->fresh();
    }

    /**
     * @param  array{company?: string|null, rep_name?: string|null, signed_at?: string|null}  $meta
     */
    public function completeUpload(ClientAccount $account, UploadedFile $file, array $meta = []): ClientAccount
    {
        if ($account->fulfillment_agreement_accepted_at !== null) {
            throw ValidationException::withMessages([
                'agreement' => ['The fulfillment agreement has already been completed.'],
            ]);
        }

        $signedAt = $this->parseSignedAt($meta['signed_at'] ?? null) ?: now();
        $company = trim((string) ($meta['company'] ?? $account->company_name));
        $repName = trim((string) ($meta['rep_name'] ?? ''));
        if ($repName === '') {
            $repName = trim(implode(' ', array_filter([
                trim((string) $account->contact_first_name),
                trim((string) $account->contact_last_name),
            ])));
        }

        $account->fulfillment_agreement_method = FulfillmentAgreementPdfService::METHOD_UPLOAD;
        $account->fulfillment_agreement_company = $company !== '' ? $company : null;
        $account->fulfillment_agreement_rep_name = $repName !== '' ? $repName : null;
        $account->fulfillment_agreement_client_signed_at = $signedAt;
        $account->fulfillment_agreement_client_signature = json_encode([
            'style' => 'upload',
            'text' => 'Manually signed (uploaded PDF)',
        ]);
        $account->fulfillment_agreement_accepted_at = now();
        $account->save();

        $account = $this->pdf->storeUploadedPdf($account, $file);
        $this->notifySlackAgreementSigned($account);

        return $account->fresh();
    }

    /**
     * @param  array{rep_name: string, signed_at?: string|null, signature_style: string, signature_text: string, signature_image: string}  $data
     */
    public function verifyAndCounterSign(ClientAccount $account, array $data, ?int $verifiedByUserId = null): ClientAccount
    {
        // Status "completed" is driven by accepted_at; path may be missing on legacy
        // click-accept rows or if PDF generation failed after the client signed.
        if ($account->fulfillment_agreement_accepted_at === null) {
            throw ValidationException::withMessages([
                'agreement' => ['The client has not completed the fulfillment agreement yet.'],
            ]);
        }

        $signedAt = $this->parseSignedAt($data['signed_at'] ?? null) ?: now();
        $imagePath = $this->pdf->storeSignatureImage($account, 'staff', (string) $data['signature_image']);
        $meta = [
            'style' => (string) $data['signature_style'],
            'text' => (string) $data['signature_text'],
        ];
        if ($imagePath !== null) {
            $meta['image_path'] = $imagePath;
        }

        $account->fulfillment_agreement_staff_rep_name = trim((string) $data['rep_name']);
        $account->fulfillment_agreement_staff_signed_at = $signedAt;
        $account->fulfillment_agreement_staff_signature = json_encode($meta);
        if ($account->fulfillment_agreement_method === null || $account->fulfillment_agreement_method === '') {
            $account->fulfillment_agreement_method = FulfillmentAgreementPdfService::METHOD_ESIGN;
        }
        $account->save();

        // Wet-ink uploads must not be lost when we rebuild the composite PDF with both signatures.
        if ($account->fulfillment_agreement_method === FulfillmentAgreementPdfService::METHOD_UPLOAD) {
            $account = $this->pdf->preserveWetInkUpload($account);
        }

        $account = $this->pdf->buildAndStoreSigned($account);

        /** @var PortalOnboardingService $onboarding */
        $onboarding = app(PortalOnboardingService::class);

        return $onboarding->setTaskVerified(
            $account,
            'fulfillment_agreement',
            true,
            $verifiedByUserId
        );
    }

    public function clearAgreement(ClientAccount $account): ClientAccount
    {
        $this->pdf->deleteStoredFiles($account);

        $account->fulfillment_agreement_method = null;
        $account->fulfillment_agreement_company = null;
        $account->fulfillment_agreement_rep_name = null;
        $account->fulfillment_agreement_client_signed_at = null;
        $account->fulfillment_agreement_client_signature = null;
        $account->fulfillment_agreement_accepted_at = null;
        $account->fulfillment_agreement_path = null;
        $account->fulfillment_agreement_original_name = null;
        $account->fulfillment_agreement_mime = null;
        $account->fulfillment_agreement_staff_rep_name = null;
        $account->fulfillment_agreement_staff_signed_at = null;
        $account->fulfillment_agreement_staff_signature = null;
        $account->save();

        /** @var PortalOnboardingService $onboarding */
        $onboarding = app(PortalOnboardingService::class);

        return $onboarding->setTaskVerified(
            $account,
            'fulfillment_agreement',
            false
        );
    }

    public function removeCounterSignature(ClientAccount $account): ClientAccount
    {
        if ($account->fulfillment_agreement_accepted_at === null) {
            throw ValidationException::withMessages([
                'agreement' => ['The client has not completed the fulfillment agreement yet.'],
            ]);
        }

        $this->pdf->deleteSignatureImage($account, 'staff');

        $account->fulfillment_agreement_staff_rep_name = null;
        $account->fulfillment_agreement_staff_signed_at = null;
        $account->fulfillment_agreement_staff_signature = null;
        $account->save();

        /** @var PortalOnboardingService $onboarding */
        $onboarding = app(PortalOnboardingService::class);
        $account = $onboarding->setTaskVerified(
            $account,
            'fulfillment_agreement',
            false
        );

        return $this->pdf->buildAndStoreSigned($account);
    }

    public function notifySlackAgreementSigned(ClientAccount $account): void
    {
        $channel = trim((string) (config('billing.slack.alerts_channel') ?: '#alerts'));
        $company = trim((string) $account->company_name) !== ''
            ? (string) $account->company_name
            : ('Account #'.$account->id);
        $viewUrl = CrmUrls::clientAccountOnboardingStaffUrl((int) $account->id);

        $text = "Agreement Signed\nAccount: {$company}\nView Agreement - {$viewUrl}";

        try {
            $this->slack->post($channel, $text, 'Save Rack');
        } catch (\Throwable $e) {
            Log::warning('fulfillment_agreement_slack_failed', [
                'client_account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function parseSignedAt($value): ?\Carbon\Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
