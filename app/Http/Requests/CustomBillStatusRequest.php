<?php

namespace App\Http\Requests;

use App\Models\CustomBill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomBillStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([CustomBill::STATUS_OPEN, CustomBill::STATUS_INVOICED])],
        ];
    }
}
