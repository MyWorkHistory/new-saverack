<?php

namespace App\Support\Legacy;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\ClientAccountBillingPreferences;
use Carbon\Carbon;

/**
 * Maps legacy `customers` row enrichment fields into `client_accounts` columns.
 */
final class LegacyCustomerAccountImportMapper
{
    /** @var array<int, int|null> */
    private static array $managerCache = [];

    /**
     * @return list<string>
     */
    public static function enrichmentFieldKeys(): array
    {
        return [
            'account_manager_id',
            'contract_date',
            'in_house_slack',
            'slack_channel',
            'stripe_customer_id',
            'whatsapp_api_id',
            'cc_fee_percent',
            'default_payment_type',
            'payment_terms_days',
            'notify_email',
            'notification_email',
            'notes',
            'brand_logo_path',
            'postage_option',
            'billing_available_funds_cents',
        ];
    }

    /**
     * @param  object  $row  stdClass from legacy customers query
     * @return array<string, mixed>
     */
    public static function mapEnrichmentFields(object $row, ?string $contactEmail = null): array
    {
        $legacyManagerId = isset($row->manager) && is_numeric($row->manager) ? (int) $row->manager : null;
        if ($legacyManagerId !== null && $legacyManagerId <= 0) {
            $legacyManagerId = null;
        }

        $managerName = self::nonEmptyString($row->manager_name ?? null);
        $accountManagerId = self::resolveAccountManagerId($legacyManagerId, $managerName);

        $contractDate = self::resolveContractDate($row);
        $inHouseSlack = self::resolveInHouseSlack($row->slack_channel ?? null);
        $clientSlackChannel = self::resolveClientSlackChannel($row);

        $stripeCustomerId = self::nullableTruncate($row->stripe_customer_id ?? null, 191);
        $whatsappApiId = self::nullableTruncate($row->wp_chat_id ?? null, 191);
        $ccFeePercent = is_numeric($row->cc_charge ?? null) ? (float) $row->cc_charge : null;

        $paymentType = self::normalizePaymentType($row->payment_method ?? null);
        $paymentTermsDays = self::normalizePaymentTermsDays($row->net ?? null);

        [$notifyEmail, $notificationEmail] = self::resolveNotificationEmail(
            $row->b_email ?? null,
            $contactEmail ?? self::normEmail($row->c_email ?? null)
        );

        $notes = self::buildNotes($row->reason ?? null, $row->status_reason ?? null);
        $logoPath = self::nullableTruncate($row->logo_path ?? null, 512);
        $postageOption = self::normalizePostageCheck($row->postage_check ?? null);
        $billingCents = self::normalizeBillingBalance($row->account_balance ?? null);

        return array_filter([
            'account_manager_id' => $accountManagerId,
            'contract_date' => $contractDate,
            'in_house_slack' => $inHouseSlack !== null ? self::truncate($inHouseSlack, 512) : null,
            'slack_channel' => $clientSlackChannel !== null ? self::truncate($clientSlackChannel, 65535) : null,
            'stripe_customer_id' => $stripeCustomerId,
            'whatsapp_api_id' => $whatsappApiId,
            'cc_fee_percent' => $ccFeePercent,
            'default_payment_type' => $paymentType,
            'payment_terms_days' => $paymentTermsDays,
            'notify_email' => $notifyEmail,
            'notification_email' => $notificationEmail,
            'notes' => $notes !== null ? self::truncate($notes, 65535) : null,
            'brand_logo_path' => $logoPath,
            'postage_option' => $postageOption,
            'billing_available_funds_cents' => $billingCents,
        ], static function ($v) {
            return $v !== null;
        });
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    public static function mergeForSync(array $mapped, ClientAccount $existing, bool $force): array
    {
        $attrs = [];

        foreach ($mapped as $key => $value) {
            if (! in_array($key, self::enrichmentFieldKeys(), true)) {
                continue;
            }

            if ($force || self::shouldFillField($key, $existing)) {
                $attrs[$key] = $value;
            }
        }

        return $attrs;
    }

    public static function resolveAccountManagerId(?int $legacyManagerId, ?string $managerName): ?int
    {
        $cacheKey = ($legacyManagerId ?? 0) * 100000 + crc32(self::normalizePersonName((string) $managerName));

        if (array_key_exists($cacheKey, self::$managerCache)) {
            return self::$managerCache[$cacheKey];
        }

        $resolved = null;

        $normalizedManagerName = self::normalizePersonName((string) $managerName);
        if ($normalizedManagerName !== '') {
            $resolved = self::findStaffUserIdByName($normalizedManagerName);
        }

        if ($resolved === null && $legacyManagerId !== null && $legacyManagerId > 0) {
            $byLegacy = User::query()
                ->whereNull('client_account_id')
                ->where('legacy_user_id', $legacyManagerId)
                ->value('id');
            if ($byLegacy !== null) {
                $resolved = (int) $byLegacy;
            }
        }

        if ($resolved === null && $legacyManagerId !== null && $legacyManagerId > 0) {
            $byCrmId = User::query()
                ->whereNull('client_account_id')
                ->where('id', $legacyManagerId)
                ->value('id');
            if ($byCrmId !== null) {
                $resolved = (int) $byCrmId;
            }
        }

        self::$managerCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Locate an existing CRM account for a legacy `customers` row.
     *
     * Match order: legacy_customer_id, exact company_name, similar company_name
     * (ignores LLC/Inc punctuation), then contact email.
     * Similar-name matches apply only when exactly one CRM account shares the key.
     */
    public static function findClientAccountForLegacyRow(object $row): ?ClientAccount
    {
        $legacyId = isset($row->id) ? (int) $row->id : 0;
        if ($legacyId > 0) {
            $byLegacy = ClientAccount::query()->where('legacy_customer_id', $legacyId)->first();
            if ($byLegacy !== null) {
                return $byLegacy;
            }
        }

        $companyName = self::legacyCompanyNameFromRow($row);
        if ($companyName !== null) {
            $normalized = self::normalizeCompanyName($companyName);
            if ($normalized !== '') {
                $byName = ClientAccount::query()
                    ->whereRaw('LOWER(TRIM(company_name)) = ?', [$normalized])
                    ->orderBy('id')
                    ->first();
                if ($byName !== null) {
                    return $byName;
                }
            }

            $bySimilar = self::findClientAccountBySimilarCompanyName($companyName);
            if ($bySimilar !== null) {
                return $bySimilar;
            }
        }

        $email = self::normEmail(self::nonEmptyString($row->c_email ?? $row->email ?? null) ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ClientAccount::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    /**
     * Unique CRM account whose similar company key matches the legacy name.
     */
    public static function findClientAccountBySimilarCompanyName(string $companyName): ?ClientAccount
    {
        $needle = self::companyNameMatchKey($companyName);
        if ($needle === '' || mb_strlen($needle) < 3) {
            return null;
        }

        $tokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstToken = (string) ($tokens[0] ?? '');
        if ($firstToken === '' || mb_strlen($firstToken) < 2) {
            return null;
        }

        $candidates = ClientAccount::query()
            ->whereRaw('LOWER(company_name) LIKE ?', ['%'.$firstToken.'%'])
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'company_name', 'legacy_customer_id', 'email']);

        $matches = [];
        foreach ($candidates as $account) {
            if (self::companyNameMatchKey((string) $account->company_name) === $needle) {
                $matches[] = $account;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * Loose company identity for similar-name matching (case/punct/legal-suffix insensitive).
     */
    public static function companyNameMatchKey(?string $name): string
    {
        $s = self::normalizeCompanyName($name);
        if ($s === '') {
            return '';
        }

        $s = preg_replace('/&/u', ' and ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        $suffixes = [
            'llc',
            'l l c',
            'inc',
            'incorporated',
            'ltd',
            'limited',
            'co',
            'corp',
            'corporation',
            'company',
            'companies',
            'plc',
            'lp',
            'llp',
            'pc',
            'pllc',
        ];

        $parts = $s === '' ? [] : explode(' ', $s);
        while ($parts !== []) {
            $last = (string) end($parts);
            if (in_array($last, $suffixes, true)) {
                array_pop($parts);
                continue;
            }
            break;
        }
        if ($parts !== [] && $parts[0] === 'the') {
            array_shift($parts);
        }

        return implode(' ', $parts);
    }

    public static function legacyCompanyNameFromRow(object $row): ?string
    {
        return self::nonEmptyString($row->company_name ?? null);
    }

    public static function normalizeCompanyName(?string $name): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        return strtolower($s);
    }

    public static function normalizePersonName(?string $name): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        return strtolower($s);
    }

    /**
     * @return array<string, mixed>
     */
    public static function legacyCustomerIdBackfill(int $legacyId, ClientAccount $existing, bool $force): array
    {
        if ($legacyId <= 0) {
            return [];
        }

        $current = (int) ($existing->legacy_customer_id ?? 0);
        if ($current === $legacyId) {
            return [];
        }

        if ($force || $current <= 0) {
            return ['legacy_customer_id' => $legacyId];
        }

        return [];
    }

    private static function findStaffUserIdByName(string $normalizedName): ?int
    {
        if ($normalizedName === '') {
            return null;
        }

        $byName = User::query()
            ->whereNull('client_account_id')
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->value('id');

        return $byName !== null ? (int) $byName : null;
    }

    public static function clearManagerCache(): void
    {
        self::$managerCache = [];
    }

    private static function shouldFillField(string $key, ClientAccount $existing): bool
    {
        switch ($key) {
            case 'account_manager_id':
                return $existing->account_manager_id === null;
            case 'contract_date':
                return $existing->contract_date === null;
            case 'in_house_slack':
                return trim((string) ($existing->in_house_slack ?? '')) === '';
            case 'slack_channel':
                return trim((string) ($existing->slack_channel ?? '')) === '';
            case 'stripe_customer_id':
                return trim((string) ($existing->stripe_customer_id ?? '')) === '';
            case 'whatsapp_api_id':
                return trim((string) ($existing->whatsapp_api_id ?? '')) === '';
            case 'cc_fee_percent':
                return $existing->cc_fee_percent === null
                    || abs((float) $existing->cc_fee_percent - 3.50) < 0.00001;
            case 'default_payment_type':
                return trim((string) ($existing->default_payment_type ?? '')) === '';
            case 'payment_terms_days':
                return $existing->payment_terms_days === null;
            case 'notify_email':
                return trim((string) ($existing->notification_email ?? '')) === '';
            case 'notification_email':
                return trim((string) ($existing->notification_email ?? '')) === '';
            case 'notes':
                return trim((string) ($existing->notes ?? '')) === '';
            case 'brand_logo_path':
                return trim((string) ($existing->brand_logo_path ?? '')) === '';
            case 'postage_option':
                return trim((string) ($existing->postage_option ?? '')) === ''
                    || $existing->postage_option === ClientAccountBillingPreferences::defaultPostageKey();
            case 'billing_available_funds_cents':
                return $existing->billing_available_funds_cents === null
                    || (int) $existing->billing_available_funds_cents === 0;
            default:
                return false;
        }
    }

    /**
     * @param  mixed  $activeDate
     * @param  mixed  $createdAt
     */
    public static function resolveContractDate(object $row): ?string
    {
        $activeDate = $row->activeDate ?? null;
        if ($activeDate !== null && $activeDate !== '' && $activeDate !== '0000-00-00') {
            try {
                return Carbon::parse($activeDate)->toDateString();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $createdAt = $row->created_at ?? null;
        if ($createdAt !== null && $createdAt !== '') {
            try {
                return Carbon::parse($createdAt)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     */
    public static function resolveInHouseSlack($raw): ?string
    {
        $s = self::normalizeScalar($raw);
        if ($s === null) {
            return null;
        }

        return ltrim($s, '#');
    }

    /**
     * @param  object  $row
     */
    public static function resolveClientSlackChannel(object $row): ?string
    {
        foreach (['group_chat', 'telegram_group', 'skype_group'] as $column) {
            $value = self::normalizeScalar($row->{$column} ?? null);
            if ($value !== null && self::looksLikeChannelUrl($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     */
    public static function normalizePaymentType($raw): ?string
    {
        $s = self::normalizeScalar($raw);
        if ($s === null) {
            return null;
        }

        $lower = strtolower($s);
        $map = [
            'ach' => 'ACH',
            'wire' => 'Wire',
            'check' => 'Check',
            'manual' => 'Manual',
            'credit card' => 'Credit Card',
            'creditcard' => 'Credit Card',
            'card' => 'Credit Card',
            'paypal' => 'Paypal',
            'varies' => 'Varies',
        ];

        if (isset($map[$lower])) {
            return $map[$lower];
        }

        foreach (ClientAccount::DEFAULT_PAYMENT_TYPES as $type) {
            if (strcasecmp($type, $s) === 0) {
                return $type;
            }
        }

        return 'Manual';
    }

    /**
     * @param  mixed  $raw
     */
    public static function normalizePaymentTermsDays($raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return ClientAccountBillingPreferences::normalizePaymentTermsDays((int) $raw);
    }

    /**
     * @param  mixed  $raw
     */
    public static function normalizePostageCheck($raw): ?string
    {
        $s = self::normalizeScalar($raw);
        if ($s === null) {
            return null;
        }

        $lower = strtolower($s);
        $map = [
            'save rack' => ClientAccountBillingPreferences::POSTAGE_SAVE_RACK_ALL,
            'saverack' => ClientAccountBillingPreferences::POSTAGE_SAVE_RACK_ALL,
            'customer usps' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_USPS,
            'usps' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_USPS,
            'customer ups' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_UPS,
            'ups' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_UPS,
            'customer fedex' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_FEDEX,
            'fedex' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_FEDEX,
            'customer multiple' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_MULTIPLE,
            'multiple' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_MULTIPLE,
        ];

        if (isset($map[$lower])) {
            return $map[$lower];
        }

        return ClientAccountBillingPreferences::normalizePostageKey($s);
    }

    /**
     * @param  mixed  $reason
     * @param  mixed  $statusReason
     */
    public static function buildNotes($reason, $statusReason): ?string
    {
        $parts = array_filter([
            self::nonEmptyString($reason),
            self::nonEmptyString($statusReason),
        ]);

        if ($parts === []) {
            return null;
        }

        return implode("\n", $parts);
    }

    /**
     * @param  mixed  $billingEmail
     * @return array{0: bool|null, 1: string|null}
     */
    public static function resolveNotificationEmail($billingEmail, ?string $contactEmail): array
    {
        $billing = self::normEmail(self::nonEmptyString($billingEmail) ?? '');
        if ($billing === '' || ! filter_var($billing, FILTER_VALIDATE_EMAIL)) {
            return [null, null];
        }

        $contact = self::normEmail($contactEmail ?? '');
        if ($contact !== '' && $billing === $contact) {
            return [null, null];
        }

        return [true, self::truncate($billing, 190)];
    }

    /**
     * @param  mixed  $raw
     */
    public static function normalizeBillingBalance($raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return max(0, (int) round((float) $raw * 100));
    }

    /**
     * @param  mixed  $v
     */
    public static function normalizeScalar($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $lower = strtolower($s);
        if ($lower === 'null' || $lower === 'inactive' || $lower === 'n/a' || $lower === 'not available') {
            return null;
        }

        return $s;
    }

    private static function looksLikeChannelUrl(string $value): bool
    {
        if (preg_match('#^https?://#i', $value)) {
            return true;
        }

        return (bool) preg_match('#^(t\.me/|join\.skype\.com/)#i', $value);
    }

    /**
     * @param  mixed  $v
     */
    private static function nonEmptyString($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private static function normEmail(?string $e): string
    {
        if ($e === null) {
            return '';
        }

        return trim(strtolower($e));
    }

    /**
     * @param  mixed  $v
     */
    private static function nullableTruncate($v, int $max): ?string
    {
        $s = self::normalizeScalar($v);

        return $s !== null ? self::truncate($s, $max) : null;
    }

    private static function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    }
}
