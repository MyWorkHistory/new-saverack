<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');
        $authUser = $this->user();

        if (! $user instanceof User || ! $authUser) {
            return false;
        }

        return $authUser->can('update', $user);
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : 0;

        return [
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['required', Rule::in(['pending', 'active', 'inactive'])],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
