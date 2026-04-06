<?php

namespace App\Http\Requests;

use App\Models\ClientStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientStoreBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'apply_status' => $this->boolean('apply_status'),
            'apply_marketplace' => $this->boolean('apply_marketplace'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_store_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_store_ids.*' => ['integer', 'exists:client_stores,id'],
            'apply_status' => ['boolean'],
            'apply_marketplace' => ['boolean'],
            'status' => ['required_if:apply_status,true', 'string', Rule::in(ClientStore::STATUSES)],
            'marketplace' => ['nullable', 'string', 'max:190'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (! $this->boolean('apply_status') && ! $this->boolean('apply_marketplace')) {
                $v->errors()->add('apply_status', 'Choose at least one: change status or change marketplace.');
            }
        });
    }
}
