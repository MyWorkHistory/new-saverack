<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientStoreBulkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_store_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_store_ids.*' => ['integer', 'exists:client_stores,id'],
        ];
    }
}
