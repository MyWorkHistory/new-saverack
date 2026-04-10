<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserBulkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
