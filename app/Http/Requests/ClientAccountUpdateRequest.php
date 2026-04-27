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

        if ($this->exists('in_house_slack')) {
            $raw = $this->input('in_house_slack');
            if ($raw === null || $raw === '') {
                $this->merge(['in_house_slack' => null]);
            } else {
                $s = trim((string) $raw);
                $s = ltrim($s, '#');
                $s = trim($s);
                $this->merge(['in_house_slack' => $s !== '' ? $s : null]);
            }
        }

        if ($this->exists('whatsapp_e164')) {
            $raw = $this->input('whatsapp_e164');
            if ($raw === null || $raw === '') {
                $this->merge(['whatsapp_e164' => null]);
            } else {
                $this->merge(['whatsapp_e164' => trim((string) $raw)]);
            }
        }

        if ($this->exists('stripe_customer_id')) {
            $raw = $this->input('stripe_customer_id');
            if ($raw === null || $raw === '') {
                $this->merge(['stripe_customer_id' => null]);
            } else {
                $this->merge(['stripe_customer_id' => trim((string) $raw)]);
            }
        }

        if ($this->exists('shiphero_customer_account_id')) {
            $raw = $this->input('shiphero_customer_account_id');
            if ($raw === null || $raw === '') {
                $this->merge(['shiphero_customer_account_id' => null]);
            } else {
                $this->merge(['shiphero_customer_account_id' => trim((string) $raw)]);
            }
        }

        if ($this->exists('whatsapp_api_id')) {
            $raw = $this->input('whatsapp_api_id');
            if ($raw === null || $raw === '') {
                $this->merge(['whatsapp_api_id' => null]);
            } else {
                $this->merge(['whatsapp_api_id' => trim((string) $raw)]);
            }
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
            /* Phone numbers, wa.me links, etc. — stored as TEXT in DB */
            'whatsapp_e164' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'slack_channel' => ['sometimes', 'nullable', 'string', 'max:255'],
            'in_house_slack' => ['sometimes', 'nullable', 'string', 'max:512'],
            'street' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:64'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'account_manager_id' => ['sometimes', 'nullable', 'integer', $accountManagerRule],
            'contract_date' => ['sometimes', 'nullable', 'date'],
            'default_payment_type' => ['sometimes', 'nullable', 'string', Rule::in(ClientAccount::DEFAULT_PAYMENT_TYPES)],
            'stripe_customer_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'shiphero_customer_account_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'whatsapp_api_id' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
