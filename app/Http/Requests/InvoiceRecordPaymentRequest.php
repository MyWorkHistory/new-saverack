<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceRecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'payment_date' => ['nullable', 'date'],
            'payment_type' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentMeta(): array
    {
        $v = $this->validated();

        return array_filter([
            'payment_date' => $v['payment_date'] ?? null,
            'payment_type' => $v['payment_type'] ?? null,
            'notes' => $v['notes'] ?? null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
