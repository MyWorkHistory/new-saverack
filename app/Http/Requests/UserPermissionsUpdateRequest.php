<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPermissionsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $keys = $this->input('permission_keys');
        $normalized = User::normalizeCrmPermissionKeys(
            is_array($keys) ? $keys : [],
            User::editableCrmPermissionKeys()
        );

        $this->merge([
            'permission_keys' => $normalized,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editableKeys = User::editableCrmPermissionKeys();

        return [
            'permission_keys' => ['present', 'array'],
            'permission_keys.*' => ['string', Rule::in($editableKeys)],
        ];
    }
}
