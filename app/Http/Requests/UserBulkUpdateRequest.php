<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'active', 'inactive'])],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasStatus = $this->filled('status');
            $hasRoles = array_key_exists('role_ids', $this->all());
            if (! $hasStatus && ! $hasRoles) {
                $validator->errors()->add('user_ids', 'Provide status and/or role_ids to update.');
            }
        });
    }
}
