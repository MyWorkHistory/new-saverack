<?php

namespace App\Http\Requests;

use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceAddItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:65535'],
            'display_name' => ['nullable', 'string', 'max:512'],
            'category' => ['nullable', 'string', Rule::in(InvoiceLineCategory::all())],
            'subtype' => ['nullable', 'string', 'max:64'],
            'group_key' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:128'],
            'service_code' => ['nullable', 'string', 'max:128'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'unit_price_cents' => ['required', 'integer'],
            'line_total_cents' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function itemPayload(): array
    {
        $v = $this->validated();
        $qty = array_key_exists('quantity', $v) ? (float) $v['quantity'] : 1.0;
        $isCredit = ($v['category'] ?? null) === InvoiceLineCategory::CREDITS;
        $unit = $isCredit ? -abs((int) $v['unit_price_cents']) : abs((int) $v['unit_price_cents']);
        $line = array_key_exists('line_total_cents', $v) ? (int) $v['line_total_cents'] : (int) round($qty * $unit);
        if ($isCredit) {
            $line = -abs($line);
        } else {
            $line = max(0, $line);
        }

        return [
            'description' => (string) $v['description'],
            'display_name' => $v['display_name'] ?? null,
            'category' => $v['category'] ?? null,
            'subtype' => $v['subtype'] ?? null,
            'group_key' => $v['group_key'] ?? null,
            'sku' => $v['sku'] ?? null,
            'service_code' => $v['service_code'] ?? null,
            'quantity' => $qty,
            'unit' => $v['unit'] ?? null,
            'unit_price_cents' => $unit,
            'line_total_cents' => $line,
            'metadata' => $v['metadata'] ?? null,
        ];
    }
}
