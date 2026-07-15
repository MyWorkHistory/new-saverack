<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\TermsOfService;
use App\Models\User;
use App\Support\HtmlSanitizer;

class TermsOfServiceService
{
    public function globalDocument(): TermsOfService
    {
        $row = TermsOfService::query()->orderBy('id')->first();
        if ($row instanceof TermsOfService) {
            return $row;
        }

        return TermsOfService::query()->create([
            'body' => '',
        ]);
    }

    public function globalBody(): string
    {
        return (string) ($this->globalDocument()->body ?? '');
    }

    /**
     * Effective terms for an account (override or global default).
     */
    public function effectiveBodyForAccount(ClientAccount $account): string
    {
        $override = $account->terms_of_service_body;
        if ($override !== null && trim((string) $override) !== '') {
            return (string) $override;
        }

        return $this->globalBody();
    }

    public function accountHasOverride(ClientAccount $account): bool
    {
        $override = $account->terms_of_service_body;

        return $override !== null && trim((string) $override) !== '';
    }

    public function toGlobalArray(TermsOfService $doc): array
    {
        return [
            'id' => $doc->id,
            'body' => (string) ($doc->body ?? ''),
            'updated_by' => $doc->updated_by,
            'updated_at' => optional($doc->updated_at)->toIso8601String(),
            'public_url' => url('/terms'),
        ];
    }

    public function toAccountArray(ClientAccount $account): array
    {
        return [
            'client_account_id' => $account->id,
            'body' => $this->effectiveBodyForAccount($account),
            'is_override' => $this->accountHasOverride($account),
            'updated_at' => optional($account->updated_at)->toIso8601String(),
            'public_url' => url('/terms/accounts/'.$account->id),
        ];
    }

    public function updateGlobal(string $body, ?User $actor = null): TermsOfService
    {
        $doc = $this->globalDocument();
        $doc->body = HtmlSanitizer::sanitize($body);
        $doc->updated_by = $actor ? $actor->id : null;
        $doc->save();

        return $doc->fresh();
    }

    public function updateAccount(ClientAccount $account, string $body): ClientAccount
    {
        $account->terms_of_service_body = HtmlSanitizer::sanitize($body);
        $account->save();

        return $account->fresh();
    }
}
