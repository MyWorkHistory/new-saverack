<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Policies\ClientAccountUserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientAccountUserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $u = $this->user();
        if (! $account instanceof ClientAccount || $u === null) {
            return false;
        }

        return app(ClientAccountUserPolicy::class)->create($u, $account);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['pending', 'active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'name.max' => 'Full name may not be greater than 150 characters.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already in use.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be pending, active, or inactive.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $account = $this->route('client_account');
            if (! $account instanceof ClientAccount) {
                return;
            }
            $email = trim((string) $this->input('email', ''));
            $accountEmail = trim((string) $account->email);
            if ($email === '' || $accountEmail === '') {
                return;
            }
            if (strcasecmp($email, $accountEmail) !== 0) {
                return;
            }
            if ($account->primaryAccountUser()->exists()) {
                $v->errors()->add('email', 'A user with this email already exists for this account.');
            }
        });
    }
}
