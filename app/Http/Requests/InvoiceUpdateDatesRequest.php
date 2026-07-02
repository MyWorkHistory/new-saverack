<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceUpdateDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'due_at' => ['nullable', 'date'],
            'billing_period_start' => ['nullable', 'date'],
            'billing_period_end' => ['nullable', 'date', 'after_or_equal:billing_period_start'],
            'payment_terms' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array{due_at?: string|null, billing_period_start?: string|null, billing_period_end?: string|null, payment_terms?: string|null}
     */
    public function datePayload(): array
    {
        $validated = $this->validated();
        $out = [];
        foreach (['due_at', 'billing_period_start', 'billing_period_end', 'payment_terms'] as $key) {
            if (array_key_exists($key, $validated)) {
                $out[$key] = $validated[$key];
            }
        }

        return $out;
    }
}
