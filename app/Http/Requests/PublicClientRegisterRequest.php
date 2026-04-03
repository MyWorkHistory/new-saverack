<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicClientRegisterRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:190'],
            'full_name' => ['required', 'string', 'max:201'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email'),
                Rule::unique('client_accounts', 'email'),
            ],
            'phone' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
