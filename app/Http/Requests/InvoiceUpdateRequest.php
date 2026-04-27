<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'size:3'],
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
            'items' => ['required', 'array', 'max:500'],
            'items.*.description' => ['required', 'string', 'max:65535'],
            'items.*.category' => ['nullable', 'string', 'max:64'],
            'items.*.subtype' => ['nullable', 'string', 'max:64'],
            'items.*.group_key' => ['nullable', 'string', 'max:128'],
            'items.*.display_name' => ['nullable', 'string', 'max:65535'],
            'items.*.sku' => ['nullable', 'string', 'max:128'],
            'items.*.service_code' => ['nullable', 'string', 'max:128'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
            'items.*.unit_price_cents' => ['required', 'integer'],
            'items.*.line_total_cents' => ['required', 'integer'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function headerPayload(): array
    {
        $v = $this->validated();
        $out = [];
        if (array_key_exists('currency', $v)) {
            $out['currency'] = strtoupper((string) $v['currency']);
        }
        foreach ([
            'issued_at', 'due_at', 'billing_period_start', 'billing_period_end',
            'tax_rate_basis_points', 'payment_terms', 'po_number',
            'customer_notes', 'internal_notes',
        ] as $key) {
            if (array_key_exists($key, $v)) {
                $out[$key] = $v[$key];
            }
        }
        if (array_key_exists('tax_cents', $v)) {
            $out['tax_cents'] = (int) $v['tax_cents'];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsPayload(): array
    {
        return array_values($this->validated()['items']);
    }
}
