<?php

namespace App\Http\Requests;

use App\Models\ClientAccount;
use App\Services\PortalOnboardingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientAccountBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_account_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_account_ids.*' => ['integer', 'exists:client_accounts,id'],
            'status' => ['required', 'string', Rule::in(ClientAccount::STATUSES)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ((string) $this->input('status') !== ClientAccount::STATUS_ACTIVE) {
                return;
            }

            $ids = $this->input('client_account_ids', []);
            if (! is_array($ids) || $ids === []) {
                return;
            }

            $onboarding = app(PortalOnboardingService::class);
            $accounts = ClientAccount::query()->whereIn('id', $ids)->get();
            foreach ($accounts as $account) {
                if (! $onboarding->isOnboardingReadyForActivation($account)) {
                    $v->errors()->add(
                        'status',
                        PortalOnboardingService::ACTIVATION_BLOCKED_MESSAGE
                    );

                    return;
                }
            }
        });
    }
}
