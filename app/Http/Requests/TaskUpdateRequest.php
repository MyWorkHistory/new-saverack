<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('update', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:pending,in_progress,review,completed'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,urgent'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
