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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric'],
        ];
    }
}
