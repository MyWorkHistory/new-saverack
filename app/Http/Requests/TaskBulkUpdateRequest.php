<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskBulkUpdateRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:pending,in_progress,review,completed'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,urgent'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->all();
            $hasField = ! empty($data['status'])
                || ! empty($data['priority'])
                || array_key_exists('assigned_to', $data);
            if (! $hasField) {
                $validator->errors()->add('task_ids', 'Provide status, priority, and/or assignee to update.');
            }
        });
    }
}
