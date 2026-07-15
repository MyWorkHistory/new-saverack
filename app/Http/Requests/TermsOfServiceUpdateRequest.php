<?php

namespace App\Http\Requests;

use App\Policies\TermsOfServicePolicy;
use Illuminate\Foundation\Http\FormRequest;

class TermsOfServiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();
        if ($u === null) {
            return false;
        }
        $doc = app(\App\Services\TermsOfServiceService::class)->globalDocument();

        return app(TermsOfServicePolicy::class)->update($u, $doc);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:500000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Terms of Service content is required.',
            'body.max' => 'Terms of Service content is too long.',
        ];
    }
}
