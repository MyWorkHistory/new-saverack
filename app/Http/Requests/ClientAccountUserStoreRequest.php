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

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $account = $this->route('client_account');
            if (! $account instanceof ClientAccount) {
                return;
            }
            $email = $this->input('email');
            if (is_string($email) && strcasecmp($email, (string) $account->email) === 0) {
                $v->errors()->add('email', 'Use a different email than the account primary login.');
            }
        });
    }
}
