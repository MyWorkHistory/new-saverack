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
            'recipients' => ['nullable', 'array', 'max:50'],
            'recipients.*' => ['email:rfc,dns', 'max:255'],
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

    /**
     * @return list<string>
     */
    public function recipientEmails(): array
    {
        $list = $this->validated()['recipients'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        $clean = array_map(static function ($value) {
            return strtolower(trim((string) $value));
        }, $list);
        $clean = array_values(array_unique(array_filter($clean, static function ($value) {
            return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL);
        })));

        return $clean;
    }
}
