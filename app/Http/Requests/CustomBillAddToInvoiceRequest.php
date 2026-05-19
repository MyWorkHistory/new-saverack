<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomBillAddToInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
        ];
    }
}
