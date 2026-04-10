<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'invoice_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('invoices', 'invoice_number'),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'billing_period_start' => ['nullable', 'date'],
            'billing_period_end' => ['nullable', 'date', 'after_or_equal:billing_period_start'],
            'tax_rate_basis_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'tax_cents' => ['nullable', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:64'],
            'po_number' => ['nullable', 'string', 'max:128'],
            'customer_notes' => ['nullable', 'string', 'max:65535'],
            'internal_notes' => ['nullable', 'string', 'max:65535'],
            'items' => ['array', 'max:500'],
            'items.*.description' => ['required', 'string', 'max:65535'],
            'items.*.sku' => ['nullable', 'string', 'max:128'],
            'items.*.service_code' => ['nullable', 'string', 'max:128'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'items.*.line_total_cents' => ['required', 'integer', 'min:0'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('currency') || $this->input('currency') === null || $this->input('currency') === '') {
            $this->merge(['currency' => 'USD']);
        }
        if (! $this->has('items') || ! is_array($this->input('items'))) {
            $this->merge(['items' => []]);
        }
        $inv = $this->input('invoice_number');
        if ($inv === '' || $inv === null) {
            $this->merge(['invoice_number' => null]);
        }
    }

    public function optionalInvoiceNumber(): ?string
    {
        $v = $this->validated();
        $n = $v['invoice_number'] ?? null;
        if (! is_string($n)) {
            return null;
        }
        $n = trim($n);

        return $n === '' ? null : $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function headerPayload(): array
    {
        $v = $this->validated();

        return [
            'client_account_id' => (int) $v['client_account_id'],
            'currency' => strtoupper((string) $v['currency']),
            'issued_at' => $v['issued_at'] ?? null,
            'due_at' => $v['due_at'] ?? null,
            'billing_period_start' => $v['billing_period_start'] ?? null,
            'billing_period_end' => $v['billing_period_end'] ?? null,
            'tax_rate_basis_points' => array_key_exists('tax_rate_basis_points', $v) ? $v['tax_rate_basis_points'] : null,
            'tax_cents' => (int) ($v['tax_cents'] ?? 0),
            'payment_terms' => $v['payment_terms'] ?? null,
            'po_number' => $v['po_number'] ?? null,
            'customer_notes' => $v['customer_notes'] ?? null,
            'internal_notes' => $v['internal_notes'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsPayload(): array
    {
        $items = $this->validated()['items'] ?? [];

        return array_values(is_array($items) ? $items : []);
    }
}
