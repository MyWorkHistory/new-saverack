<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TicketCommentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isCrmOwner();
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65535'],
        ];
    }
}
