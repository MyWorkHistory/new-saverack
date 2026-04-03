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
        $normalized = User::normalizeCrmPermissionKeys(is_array($keys) ? $keys : []);

        $this->merge([
            'permission_keys' => $normalized,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission_keys' => ['present', 'array'],
            'permission_keys.*' => ['string', Rule::in(User::CRM_MODULE_PERMISSION_KEYS)],
        ];
    }
}
