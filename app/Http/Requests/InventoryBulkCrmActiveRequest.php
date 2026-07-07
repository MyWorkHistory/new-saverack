<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryBulkCrmActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('inventory.update');
    }

    public function rules(): array
    {
        return [
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'active' => ['required', 'boolean'],
            'skus' => ['required', 'array', 'min:1', 'max:200'],
            'skus.*' => ['required', 'string', 'max:255'],
        ];
    }
}
