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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission_keys' => ['required', 'array'],
            'permission_keys.*' => ['string', Rule::in(User::CRM_MODULE_PERMISSION_KEYS)],
        ];
    }
}
