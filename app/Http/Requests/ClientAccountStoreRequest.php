<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u !== null && $u->can('create', ClientAccount::class);
    }

    public function rules(): array
    {
        return $this->commonRules();
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        $staffManagerRule = Rule::exists('users', 'id')->where(function ($query) {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'staff'));
        });

        return [
            'company_name' => ['required', 'string', 'max:190'],
            'contact_first_name' => ['nullable', 'string', 'max:100'],
            'contact_last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:client_accounts,email'],
            'notify_email' => ['sometimes', 'boolean'],
            'telegram_handle' => ['nullable', 'string', 'max:190'],
            'whatsapp_e164' => ['nullable', 'string', 'max:32'],
            'account_manager_id' => ['nullable', 'integer', $staffManagerRule],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('notify_email')) {
            $this->merge(['notify_email' => true]);
        }
        if ($this->input('account_manager_id') === '') {
            $this->merge(['account_manager_id' => null]);
        }
    }
}
