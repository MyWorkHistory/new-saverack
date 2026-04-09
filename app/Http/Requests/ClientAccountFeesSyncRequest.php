<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientAccountFeesSyncRequest extends FormRequest
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
            'fulfillment' => ['required', 'array'],
            'fulfillment.first_pick_fee' => ['nullable', 'numeric'],
            'fulfillment.additional_picks_fee' => ['nullable', 'numeric'],
            'returns' => ['required', 'array'],
            'returns.processing_fee' => ['nullable', 'numeric'],
            'returns.additional_items_fee' => ['nullable', 'numeric'],
            'storage' => ['nullable', 'array'],
            'storage.*.id' => ['nullable', 'integer'],
            'storage.*.label' => ['required', 'string', 'max:255'],
            'storage.*.amount' => ['nullable', 'numeric'],
            'storage.*.currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
