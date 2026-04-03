<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $u = $this->user();

        return $account instanceof ClientAccount && $u !== null && $u->can('update', $account);
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('account_manager_id') === '') {
            $this->merge(['account_manager_id' => null]);
        }
    }

    public function rules(): array
    {
        $staffManagerRule = Rule::exists('users', 'id')->where(function ($query) {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'staff'));
        });

        return [
            'status' => ['sometimes', 'string', Rule::in(ClientAccount::STATUSES)],
            'company_name' => ['sometimes', 'string', 'max:190'],
            'contact_first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'email',
                'max:190',
                Rule::unique('client_accounts', 'email')->ignore($this->route('client_account')),
            ],
            'notify_email' => ['sometimes', 'boolean'],
            'telegram_handle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'whatsapp_e164' => ['sometimes', 'nullable', 'string', 'max:32'],
            'account_manager_id' => ['sometimes', 'nullable', 'integer', $staffManagerRule],
        ];
    }
}
