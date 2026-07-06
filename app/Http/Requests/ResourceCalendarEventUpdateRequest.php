<?php

namespace App\Http\Requests;

use App\Models\ResourceCalendarEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResourceCalendarEventUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ResourceCalendarEvent|null $event */
        $event = $this->route('calendarEvent');

        return $event && $this->user() && $this->user()->can('update', $event);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', Rule::in(ResourceCalendarEvent::CATEGORIES)],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'is_personal' => ['sometimes', 'boolean'],
        ];
    }
}
