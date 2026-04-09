<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Models\ClientStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientStoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $u = $this->user();

        return $account instanceof ClientAccount && $u !== null && $u->can('createStore', $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'website' => ['nullable', 'string', 'max:512'],
            'marketplace' => ['nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'string', Rule::in(ClientStore::STATUSES)],
        ];
    }
}
