<?php

namespace App\Http\Requests;

use App\Support\Billing\CustomBillLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomBillStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'bill_date' => ['required', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.line_type' => ['required', 'string', Rule::in(CustomBillLineType::acceptedLineTypes())],
            'items.*.name' => ['required', 'string', 'max:512'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric'],
            'items.*.unit_price_cents' => ['nullable', 'integer'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function headerPayload(): array
    {
        return [
            'client_account_id' => (int) $this->input('client_account_id'),
            'bill_date' => $this->input('bill_date'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsPayload(): array
    {
        $items = $this->input('items');

        return is_array($items) ? $items : [];
    }
}
