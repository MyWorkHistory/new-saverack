<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientAccountBulkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_account_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_account_ids.*' => ['integer', 'exists:client_accounts,id'],
        ];
    }
}
