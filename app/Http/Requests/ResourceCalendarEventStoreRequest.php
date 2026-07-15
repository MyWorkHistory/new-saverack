<?php

namespace App\Http\Requests;

use App\Models\ResourceCalendarEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResourceCalendarEventStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('create', ResourceCalendarEvent::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(ResourceCalendarEvent::CATEGORIES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:65535'],
            'is_personal' => ['sometimes', 'boolean'],
            'repeat' => ['sometimes', 'string', Rule::in(ResourceCalendarEvent::REPEATS)],
        ];
    }
}
