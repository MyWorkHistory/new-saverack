<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceAddCcFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function label(): string
    {
        $label = $this->validated()['label'] ?? 'Credit Card Fee';
        if (! is_string($label) || trim($label) === '') {
            return 'Credit Card Fee';
        }

        return trim($label);
    }
}
