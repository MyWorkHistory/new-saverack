<?php

namespace App\Http\Requests;

use App\Support\Billing\CustomBillLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomBillItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'line_type' => ['required', 'string', Rule::in(CustomBillLineType::all())],
            'name' => ['required', 'string', 'max:512'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price' => ['nullable', 'numeric'],
            'unit_price_cents' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:255'],
        ];
    }
}
