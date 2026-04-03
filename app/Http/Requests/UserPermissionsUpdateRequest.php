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
        if (! is_array($keys)) {
            return;
        }

        $allowed = array_flip(User::CRM_MODULE_PERMISSION_KEYS);
        $filtered = [];
        foreach ($keys as $k) {
            if (is_string($k) && isset($allowed[$k])) {
                $filtered[] = $k;
            }
        }

        $this->merge([
            'permission_keys' => array_values(array_unique($filtered)),
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
