<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Models\User;
use App\Policies\ClientAccountUserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientAccountUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $target = $this->route('user');
        $u = $this->user();
        if (! $account instanceof ClientAccount || ! $target instanceof User || $u === null) {
            return false;
        }

        return app(ClientAccountUserPolicy::class)->update($u, $target, $account);
    }

    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');
        $userId = $target instanceof User ? $target->id : 0;
        $isPrimary = $target instanceof User && $target->is_account_primary;

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
            'phone' => ['nullable', 'string', 'max:50'],
        ];

        if (! $isPrimary) {
            $rules['email'] = ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'name.max' => 'Full name may not be greater than 150 characters.',
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already in use.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be active or inactive.',
        ];
    }
}

