<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientAccountFeeAmountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');

        return $account !== null && $this->user() !== null && $this->user()->can('update', $account);
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'cost'] as $key) {
            if ($this->exists($key) && $this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
