<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Models\ClientAccountComment;
use Illuminate\Foundation\Http\FormRequest;

class ClientAccountCommentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('client_account');
        $comment = $this->route('comment');
        $u = $this->user();

        if (! $account instanceof ClientAccount || ! $comment instanceof ClientAccountComment || $u === null) {
            return false;
        }

        return $u->can('modifyComment', [$account, $comment]);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65535'],
        ];
    }
}
