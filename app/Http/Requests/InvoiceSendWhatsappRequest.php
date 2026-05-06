<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceSendWhatsappRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['send_invoice', 'invoice_reminder', 'send_storage_invoice', 'payment_failed'])],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function actionType(): string
    {
        $type = $this->validated()['type'] ?? 'send_invoice';
        if (! is_string($type) || trim($type) === '') {
            return 'send_invoice';
        }

        return trim($type);
    }

    public function messageText(): ?string
    {
        $msg = $this->validated()['message'] ?? null;
        if (! is_string($msg)) {
            return null;
        }
        $msg = trim($msg);

        return $msg === '' ? null : $msg;
    }
}
