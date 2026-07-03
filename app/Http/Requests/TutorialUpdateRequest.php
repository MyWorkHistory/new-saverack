<?php

namespace App\Http\Requests;

use App\Models\Tutorial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TutorialUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tutorial = $this->route('tutorial');

        return $tutorial instanceof Tutorial
            && $this->user()
            && $this->user()->can('update', $tutorial);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'category' => ['sometimes', 'required', 'string', Rule::in(Tutorial::CATEGORIES)],
        ];
    }
}
