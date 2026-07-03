<?php

namespace App\Http\Requests;

use App\Models\ResourcePhoto;
use Illuminate\Foundation\Http\FormRequest;

class ResourcePhotoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('create', ResourcePhoto::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'photo' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp',
            ],
        ];
    }
}
