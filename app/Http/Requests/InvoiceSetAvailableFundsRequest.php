<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceSetAvailableFundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function amountCents(): int
    {
        return (int) ($this->validated()['amount_cents'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentMeta(): array
    {
        $v = $this->validated();

        return array_filter([
            'notes' => $v['notes'] ?? null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }
}
