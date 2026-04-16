<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceAllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer', 'min:1'],
            'payment_date' => ['nullable', 'date'],
            'payment_type' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<int>
     */
    public function invoiceIds(): array
    {
        $ids = $this->validated()['invoice_ids'] ?? [];

        return array_values(array_unique(array_map(static function ($value) {
            return (int) $value;
        }, $ids)));
    }

    public function amountCents(): int
    {
        return (int) ($this->validated()['amount_cents'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentMeta(): array
    {
        $v = $this->validated();

        return array_filter([
            'payment_date' => $v['payment_date'] ?? null,
            'payment_type' => $v['payment_type'] ?? null,
            'notes' => $v['notes'] ?? null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
