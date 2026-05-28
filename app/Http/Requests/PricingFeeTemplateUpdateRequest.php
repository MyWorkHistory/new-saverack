<?php

namespace App\Http\Requests;

use App\Models\PricingFeeTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PricingFeeTemplateUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'required', 'string', Rule::in(PricingFeeTemplate::CATEGORIES)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'icon' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_icon' => ['sometimes', 'boolean'],
        ];
    }
}
