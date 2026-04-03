<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\ClientAccount;

class ClientAccountBulkUpdateRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(ClientAccount::STATUSES)],
        ];
    }
}
