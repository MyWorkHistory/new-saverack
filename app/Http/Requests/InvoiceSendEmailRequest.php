<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceSendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messageText(): ?string
    {
        $msg = $this->validated()['message'] ?? null;
        if (! is_string($msg)) {
            return null;
        }
        $msg = trim($msg);

        return $msg === '' ? null : $msg;
    }
}
