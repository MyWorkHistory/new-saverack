<?php

namespace App\Http\Requests;

use App\Models\ClientAccountOnDemandProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnDemandProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('onDemandProduct');

        return $product instanceof ClientAccountOnDemandProduct
            && (bool) $this->user()?->can('update', $product);
    }

    public function rules(): array
    {
        $product = $this->route('onDemandProduct');
        $accountId = $this->input('client_account_id', $product?->client_account_id);

        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'sku' => [
                'required',
                'string',
                'max:128',
                Rule::unique('client_account_on_demand_products', 'sku')
                    ->where(fn ($query) => $query->where('client_account_id', $accountId))
                    ->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(ClientAccountOnDemandProduct::CATEGORIES)],
            'price_cents' => ['required', 'integer', 'min:0', 'max:999999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('sku')) {
            $this->merge([
                'sku' => ClientAccountOnDemandProduct::normalizeSku((string) $this->input('sku')),
            ]);
        }
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
