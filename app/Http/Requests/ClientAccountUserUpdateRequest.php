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
            'status' => ['sometimes', 'required', Rule::in(['pending', 'active', 'inactive'])],
        ];

        if (! $isPrimary) {
            $rules['email'] = ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)];
        }

        return $rules;
    }
}
