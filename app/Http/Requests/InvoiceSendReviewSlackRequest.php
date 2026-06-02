<?php

namespace App\Http\Requests;

use App\Support\InvoiceReviewReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceSendReviewSlackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(InvoiceReviewReason::keys())],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function reasonKey(): string
    {
        return (string) $this->validated()['reason'];
    }

    public function noteText(): ?string
    {
        $note = $this->validated()['note'] ?? null;
        if (! is_string($note)) {
            return null;
        }
        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
