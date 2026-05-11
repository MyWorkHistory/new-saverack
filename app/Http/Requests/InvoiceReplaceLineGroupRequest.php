<?php

namespace App\Http\Requests;

use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceReplaceLineGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'replace_item_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'replace_item_ids.*' => ['integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.description' => ['required', 'string', 'max:65535'],
            'items.*.category' => ['nullable', 'string', Rule::in(InvoiceLineCategory::all())],
            'items.*.subtype' => ['nullable', 'string', 'max:64'],
            'items.*.display_name' => ['nullable', 'string', 'max:512'],
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
     * @return list<array<string, mixed>>
     */
    public function itemsPayload(): array
    {
        return array_values($this->validated()['items']);
    }

    /**
     * @return list<int>|null
     */
    public function replaceItemIds(): ?array
    {
        $data = $this->validated();
        if (! isset($data['replace_item_ids']) || ! is_array($data['replace_item_ids'])) {
            return null;
        }

        return array_values(array_unique(array_map('intval', $data['replace_item_ids'])));
    }
}
