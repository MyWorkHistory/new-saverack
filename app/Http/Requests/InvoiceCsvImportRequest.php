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
            'invoice_date_from' => ['nullable', 'date'],
            'invoice_date_to' => ['nullable', 'date', 'after_or_equal:invoice_date_from'],
            'invoice_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('invoices', 'invoice_number'),
            ],
            // Keep parity with legacy CRM import allowance (50 MB).
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ];
    }

    public function messages(): array
    {
        return [
            'due_at.required' => 'Due date is required.',
            'due_at.date' => 'Due date must be a valid date.',
            'invoice_date_from.date' => 'Invoice date from must be a valid date.',
            'invoice_date_to.date' => 'Invoice date to must be a valid date.',
            'invoice_date_to.after_or_equal' => 'Invoice date to must be on or after invoice date from.',
            'invoice_number.unique' => 'This invoice number is already in use.',
            'file.required' => 'CSV file is required.',
            'file.file' => 'Upload a valid file.',
            'file.mimes' => 'File must be a CSV or TXT file.',
            'file.max' => 'CSV file must be 50 MB or smaller.',
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

    public function optionalInvoiceDateFrom(): ?string
    {
        $date = $this->validated()['invoice_date_from'] ?? null;
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        return Carbon::parse($date)->toDateString();
    }

    public function optionalInvoiceDateTo(): ?string
    {
        $date = $this->validated()['invoice_date_to'] ?? null;
        if (! is_string($date) || trim($date) === '') {
            return null;
        }
        return Carbon::parse($date)->toDateString();
    }
}
