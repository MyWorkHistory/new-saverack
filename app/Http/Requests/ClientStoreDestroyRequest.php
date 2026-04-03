<?php

namespace App\Http\Requests;

use App\Models\ClientStore;
use Illuminate\Foundation\Http\FormRequest;

class ClientStoreDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('client_store');
        $u = $this->user();

        return $store instanceof ClientStore && $u !== null && $u->can('delete', $store);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
