<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceStripeChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string', 'max:128'],
            'amount_cents' => ['nullable', 'integer', 'min:1', 'max:99999999999'],
            'payment_date' => ['nullable', 'date'],
            'payment_type' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function paymentMethodId(): string
    {
        return trim((string) ($this->validated()['payment_method_id'] ?? ''));
    }

    public function amountCents(): ?int
    {
        $v = $this->validated();
        if (! array_key_exists('amount_cents', $v) || $v['amount_cents'] === null) {
            return null;
        }

        return (int) $v['amount_cents'];
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
