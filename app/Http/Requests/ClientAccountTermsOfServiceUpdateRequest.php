<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Policies\ClientAccountPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ClientAccountTermsOfServiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $u = $this->user();
        if (! $account instanceof ClientAccount || $u === null) {
            return false;
        }

        return app(ClientAccountPolicy::class)->update($u, $account);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:500000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Terms of Service content is required.',
            'body.max' => 'Terms of Service content is too long.',
        ];
    }
}
