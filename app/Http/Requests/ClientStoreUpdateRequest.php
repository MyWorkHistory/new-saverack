<?php

namespace App\Http\Requests;

use App\Models\ClientStore;
use Illuminate\Foundation\Http\FormRequest;

class ClientStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('client_store');
        $u = $this->user();

        return $store instanceof ClientStore && $u !== null && $u->can('update', $store);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:190'],
            'website' => ['sometimes', 'nullable', 'string', 'max:512'],
            'marketplace' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
