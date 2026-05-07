<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceUpdateNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $invoiceId = is_object($invoice) && isset($invoice->id) ? (int) $invoice->id : null;

        return [
            'invoice_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('invoices', 'invoice_number')->ignore($invoiceId),
            ],
        ];
    }

    public function invoiceNumber(): string
    {
        return trim((string) $this->validated()['invoice_number']);
    }
}
