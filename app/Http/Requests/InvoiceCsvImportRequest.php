<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'due_at' => ['required', 'date'],
            'invoice_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('invoices', 'invoice_number'),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }

    public function dueDateString(): string
    {
        return Carbon::parse($this->validated()['due_at'])->toDateString();
    }

    public function optionalInvoiceNumber(): ?string
    {
        $n = $this->validated()['invoice_number'] ?? null;
        if (! is_string($n)) {
            return null;
        }
        $n = trim($n);

        return $n === '' ? null : $n;
    }
}
