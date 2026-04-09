<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use Illuminate\Foundation\Http\FormRequest;

class ClientAccountCommentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $u = $this->user();

        return $account instanceof ClientAccount && $u !== null && $u->can('comment', $account);
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
