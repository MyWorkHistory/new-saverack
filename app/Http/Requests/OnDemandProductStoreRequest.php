<?php

namespace App\Http\Requests;

use App\Models\ClientAccountOnDemandProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnDemandProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', ClientAccountOnDemandProduct::class);
    }

    public function rules(): array
    {
        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'sku' => [
                'required',
                'string',
                'max:128',
                Rule::unique('client_account_on_demand_products', 'sku')
                    ->where(fn ($query) => $query->where('client_account_id', $this->input('client_account_id'))),
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
