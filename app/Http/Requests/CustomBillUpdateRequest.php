<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomBillUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bill_date' => ['required', 'date'],
        ];
    }
}
