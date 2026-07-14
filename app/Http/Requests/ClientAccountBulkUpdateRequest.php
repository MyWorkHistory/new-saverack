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
            'pause_reason' => ['sometimes', 'nullable', 'string', Rule::in(ClientAccount::PAUSE_REASONS)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $newStatus = (string) $this->input('status');
            $ids = $this->input('client_account_ids', []);
            if (! is_array($ids) || $ids === []) {
                return;
            }

            if ($newStatus === ClientAccount::STATUS_PAUSED) {
                $reason = $this->input('pause_reason');
                $hasReason = is_string($reason) && trim($reason) !== '';
                if (! $hasReason) {
                    $accounts = ClientAccount::query()->whereIn('id', $ids)->get();
                    foreach ($accounts as $account) {
                        $alreadyPaused = strtolower(trim((string) $account->status)) === ClientAccount::STATUS_PAUSED;
                        if (! $alreadyPaused) {
                            $v->errors()->add(
                                'pause_reason',
                                'A pause reason is required when setting accounts to paused.'
                            );

                            return;
                        }
                    }
                }
            }

            if ($newStatus !== ClientAccount::STATUS_ACTIVE) {
                return;
            }

            $actor = $this->user();
            $canBypassOnboarding = $actor !== null
                && ($actor->isAdministrator() || $actor->isCrmOwner());
            if ($canBypassOnboarding) {
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
