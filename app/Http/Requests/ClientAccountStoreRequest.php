<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Models\User;
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $email = $this->input('email');
            if (is_string($email) && $email !== '' && User::query()->where('email', $email)->exists()) {
                $validator->errors()->add('email', 'A user with this email already exists.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        $accountManagerRule = Rule::exists('users', 'id')->whereNull('client_account_id');

        return [
            'company_name' => ['required', 'string', 'max:190'],
            'full_name' => ['required', 'string', 'max:201'],
            'brand_name' => ['nullable', 'string', 'max:190'],
            'website' => ['nullable', 'string', 'max:512'],
            'contact_first_name' => ['nullable', 'string', 'max:100'],
            'contact_last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:client_accounts,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:64'],
            'notify_email' => ['sometimes', 'boolean'],
            'telegram_handle' => ['nullable', 'string', 'max:190'],
            'whatsapp_e164' => ['nullable', 'string', 'max:32'],
            'street' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:64'],
            'zip' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:120'],
            'account_manager_id' => ['nullable', 'integer', $accountManagerRule],
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

        $fn = trim((string) $this->input('full_name', ''));
        if ($fn !== '') {
            $parts = preg_split('/\s+/', $fn, 2, PREG_SPLIT_NO_EMPTY);
            if ($parts !== false && $parts !== []) {
                $this->merge([
                    'contact_first_name' => $parts[0],
                    'contact_last_name' => isset($parts[1]) ? $parts[1] : null,
                ]);
            }
        }
    }
}
