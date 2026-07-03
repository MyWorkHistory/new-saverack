<?php

namespace App\Http\Requests;

use App\Models\Tutorial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TutorialStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('create', Tutorial::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'category' => ['required', 'string', Rule::in(Tutorial::CATEGORIES)],
        ];
    }
}
