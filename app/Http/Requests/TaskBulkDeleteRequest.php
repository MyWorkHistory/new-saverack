<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskBulkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'task_ids' => ['required', 'array', 'min:1', 'max:500'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
        ];
    }
}
