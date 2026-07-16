<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Support\HtmlSanitizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FulfillmentAgreementPdfService
{
    public const METHOD_UPLOAD = 'upload';

    public const METHOD_ESIGN = 'esign';

    public const DISK = 'local';

    /** @var TermsOfServiceService */
    protected $terms;

    public function __construct(TermsOfServiceService $terms)
    {
        $this->terms = $terms;
    }

    public function directoryForAccount(ClientAccount $account): string
    {
        return 'fulfillment-agreements/'.$account->id;
    }

    public function downloadBlank(ClientAccount $account): Response
    {
        $pdf = Pdf::loadView('pdf.fulfillment-agreement', $this->viewData($account, false, false));

        return $pdf->stream($this->blankFilename($account));
    }

    /**
     * Build and persist a signed PDF for e-sign / admin counter-sign flows.
     */
    public function buildAndStoreSigned(ClientAccount $account): ClientAccount
    {
        $includeClient = $account->fulfillment_agreement_client_signed_at !== null
            || trim((string) $account->fulfillment_agreement_company) !== ''
            || trim((string) $account->fulfillment_agreement_rep_name) !== '';
        $includeStaff = $account->fulfillment_agreement_staff_signed_at !== null
            || trim((string) $account->fulfillment_agreement_staff_rep_name) !== '';

        $pdf = Pdf::loadView('pdf.fulfillment-agreement', $this->viewData($account, $includeClient, $includeStaff));
        $binary = $pdf->output();

        $disk = Storage::disk(self::DISK);
        $dir = $this->directoryForAccount($account);
        $filename = 'fulfillment-agreement-signed.pdf';
        $path = $dir.'/'.$filename;

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
        $disk->put($path, $binary);

        $account->fulfillment_agreement_path = $path;
        $account->fulfillment_agreement_original_name = $filename;
        $account->fulfillment_agreement_mime = 'application/pdf';
        $account->save();

        return $account->fresh();
    }

    public function storeUploadedPdf(ClientAccount $account, \Illuminate\Http\UploadedFile $file): ClientAccount
    {
        $disk = Storage::disk(self::DISK);
        $dir = $this->directoryForAccount($account);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $file->getClientOriginalName());
        if ($safeName === null || $safeName === '' || $safeName === '-') {
            $safeName = 'fulfillment-agreement-upload.pdf';
        }
        $filename = uniqid('upload-', true).'-'.$safeName;

        if ($account->fulfillment_agreement_path && $disk->exists($account->fulfillment_agreement_path)) {
            $disk->delete($account->fulfillment_agreement_path);
        }

        $stored = $file->storeAs($dir, $filename, self::DISK);
        if (! is_string($stored) || $stored === '') {
            throw new \RuntimeException('Could not store fulfillment agreement upload.');
        }
        $path = $stored;

        $meta = $this->decodeSignatureMeta($account->fulfillment_agreement_client_signature);
        $meta['style'] = 'upload';
        $meta['text'] = 'Manually signed (uploaded PDF)';
        $meta['upload_path'] = $path;

        $account->fulfillment_agreement_path = $path;
        $account->fulfillment_agreement_original_name = $file->getClientOriginalName() ?: $safeName;
        $account->fulfillment_agreement_mime = $file->getMimeType() ?: 'application/pdf';
        $account->fulfillment_agreement_client_signature = json_encode($meta);
        $account->save();

        return $account->fresh();
    }

    /**
     * Keep the client's wet-ink upload before regenerating a composite PDF on verify.
     */
    public function preserveWetInkUpload(ClientAccount $account): ClientAccount
    {
        $path = (string) $account->fulfillment_agreement_path;
        $disk = Storage::disk(self::DISK);
        if ($path === '' || ! $disk->exists($path)) {
            return $account;
        }

        $dir = $this->directoryForAccount($account);
        $wetPath = $dir.'/client-wet-ink.pdf';
        if ($path !== $wetPath) {
            $disk->put($wetPath, $disk->get($path));
        }

        $meta = $this->decodeSignatureMeta($account->fulfillment_agreement_client_signature);
        $meta['style'] = isset($meta['style']) ? (string) $meta['style'] : 'upload';
        $meta['text'] = ! empty($meta['text'])
            ? (string) $meta['text']
            : 'Manually signed (uploaded PDF)';
        $meta['upload_path'] = $wetPath;

        $account->fulfillment_agreement_client_signature = json_encode($meta);
        $account->save();

        return $account->fresh();
    }

    public function storeSignatureImage(ClientAccount $account, string $role, string $dataUriOrBase64): ?string
    {
        $role = $role === 'staff' ? 'staff' : 'client';
        $binary = $this->decodeImagePayload($dataUriOrBase64);
        if ($binary === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $dir = $this->directoryForAccount($account);
        $path = $dir.'/'.$role.'-signature.png';
        $disk->put($path, $binary);

        return $path;
    }

    public function signedPdfResponse(ClientAccount $account): Response
    {
        $path = (string) $account->fulfillment_agreement_path;
        $disk = Storage::disk(self::DISK);

        if (($path === '' || ! $disk->exists($path)) && $account->fulfillment_agreement_accepted_at !== null) {
            $account = $this->buildAndStoreSigned($account);
            $path = (string) $account->fulfillment_agreement_path;
        }

        if ($path === '' || ! $disk->exists($path)) {
            abort(404, 'Signed agreement not found.');
        }

        $name = $account->fulfillment_agreement_original_name ?: 'fulfillment-agreement-signed.pdf';
        $mime = $account->fulfillment_agreement_mime ?: 'application/pdf';

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $name).'"',
        ]);
    }

    /**
     * @return array{bodyHtml: string, client: array<string, mixed>, staff: array<string, mixed>}
     */
    public function viewData(ClientAccount $account, bool $includeClient, bool $includeStaff): array
    {
        $body = HtmlSanitizer::sanitize($this->terms->effectiveBodyForAccount($account));

        return [
            'bodyHtml' => $body !== '' ? $body : '<p>Fulfillment agreement terms are not available.</p>',
            'client' => $includeClient ? $this->clientBlock($account) : [
                'company' => null,
                'rep_name' => null,
                'signed_at_label' => null,
                'signature_data_uri' => null,
                'signature_text' => null,
            ],
            'staff' => $includeStaff ? $this->staffBlock($account) : [
                'rep_name' => null,
                'signed_at_label' => null,
                'signature_data_uri' => null,
                'signature_text' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientBlock(ClientAccount $account): array
    {
        $meta = $this->decodeSignatureMeta($account->fulfillment_agreement_client_signature);
        $signatureText = isset($meta['text']) ? (string) $meta['text'] : null;
        // Upload flow has no rendered cursive image; keep a clear pen-signed note on the PDF.
        if (($meta['style'] ?? null) === 'upload' && ($signatureText === null || $signatureText === '')) {
            $signatureText = 'Manually signed (uploaded PDF)';
        }

        return [
            'company' => $account->fulfillment_agreement_company,
            'rep_name' => $account->fulfillment_agreement_rep_name,
            'signed_at_label' => $this->formatSignedAt($account->fulfillment_agreement_client_signed_at),
            'signature_data_uri' => $this->signatureDataUriFromMeta($meta),
            'signature_text' => $signatureText,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffBlock(ClientAccount $account): array
    {
        $meta = $this->decodeSignatureMeta($account->fulfillment_agreement_staff_signature);

        return [
            'rep_name' => $account->fulfillment_agreement_staff_rep_name,
            'signed_at_label' => $this->formatSignedAt($account->fulfillment_agreement_staff_signed_at),
            'signature_data_uri' => $this->signatureDataUriFromMeta($meta),
            'signature_text' => isset($meta['text']) ? (string) $meta['text'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSignatureMeta(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['text' => $raw];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function signatureDataUriFromMeta(array $meta): ?string
    {
        if (! empty($meta['image_path']) && is_string($meta['image_path'])) {
            $disk = Storage::disk(self::DISK);
            if ($disk->exists($meta['image_path'])) {
                return 'data:image/png;base64,'.base64_encode($disk->get($meta['image_path']));
            }
        }
        if (! empty($meta['image_base64']) && is_string($meta['image_base64'])) {
            $payload = $meta['image_base64'];
            if (strpos($payload, 'data:image') === 0) {
                return $payload;
            }

            return 'data:image/png;base64,'.$payload;
        }

        return null;
    }

    private function decodeImagePayload(string $payload): ?string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }
        if (preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $payload, $m)) {
            $payload = substr($payload, strpos($payload, ',') + 1);
        }
        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        return $binary;
    }

    private function formatSignedAt($value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('F j, Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function blankFilename(ClientAccount $account): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $account->company_name) ?: 'account';

        return 'fulfillment-agreement-'.$slug.'.pdf';
    }
}
