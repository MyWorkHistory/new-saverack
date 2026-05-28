<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_number' => ['required', 'string', 'max:128'],
            'shop_name' => ['required', 'string', 'max:190'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.first_name' => ['required', 'string', 'max:120'],
            'shipping_address.last_name' => ['required', 'string', 'max:120'],
            'shipping_address.address1' => ['required', 'string', 'max:255'],
            'shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'shipping_address.company' => ['nullable', 'string', 'max:190'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
            'shipping_address.state' => ['required', 'string', 'max:120'],
            'shipping_address.zip' => ['required', 'string', 'max:32'],
            'shipping_address.country' => ['required', 'string', 'max:8'],
            'shipping_address.email' => ['nullable', 'email', 'max:190'],
            'shipping_address.phone' => ['nullable', 'string', 'max:50'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.sku' => ['required', 'string', 'max:128'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            'line_items.*.price' => ['required', 'numeric', 'min:0'],
            'line_items.*.product_name' => ['nullable', 'string', 'max:255'],
            'line_items.*.partner_line_item_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
