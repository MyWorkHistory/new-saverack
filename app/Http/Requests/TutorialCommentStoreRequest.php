<?php

namespace App\Http\Requests;

use App\Models\Tutorial;
use Illuminate\Foundation\Http\FormRequest;

class TutorialCommentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tutorial = $this->route('tutorial');

        return $tutorial instanceof Tutorial
            && $this->user()
            && $this->user()->can('comment', $tutorial);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65535'],
            'attachment' => [
                'nullable',
                'file',
                'max:5120',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ];
    }
}
