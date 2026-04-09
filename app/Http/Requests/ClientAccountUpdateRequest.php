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
        $accountManagerRule = Rule::exists('users', 'id')->whereNull('client_account_id');

        return [
            'status' => ['sometimes', 'string', Rule::in(ClientAccount::STATUSES)],
            'company_name' => ['sometimes', 'string', 'max:190'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'website' => ['sometimes', 'nullable', 'string', 'max:512'],
            'contact_first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'email',
                'max:190',
                Rule::unique('client_accounts', 'email')->ignore($this->route('client_account')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notify_email' => ['sometimes', 'boolean'],
            'telegram_handle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'whatsapp_e164' => ['sometimes', 'nullable', 'string', 'max:32'],
            'slack_channel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:64'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'account_manager_id' => ['sometimes', 'nullable', 'integer', $accountManagerRule],
            'contract_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
