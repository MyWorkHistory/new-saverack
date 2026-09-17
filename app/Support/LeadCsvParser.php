<?php

namespace App\Support;

use App\Models\Lead;

/**
 * Parse a leads CSV. Quoted commas are preserved. Rows without an email are invalid.
 */
final class LeadCsvParser
{
    /**
     * @return array{rows: list<array{name: string, company_name: string, email: string, referral: string, status: string, last_activity: string|null}>, invalid: int}
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['rows' => [], 'invalid' => 0];
        }
        fwrite($handle, $contents);
        rewind($handle);

        $header = null;
        $rows = [];
        $invalid = 0;

        while (($fields = fgetcsv($handle)) !== false) {
            if (! is_array($fields) || self::isBlankRow($fields)) {
                continue;
            }

            if ($header === null) {
                $mapped = self::headerMap($fields);
                if ($mapped !== null) {
                    $header = $mapped;
                    continue;
                }
                $header = self::defaultMap();
            }

            $row = self::rowFromFields($header, $fields);
            if ($row === null) {
                $row = self::rowFromEmailAnchor($fields);
            }
            if ($row === null) {
                $invalid++;
                continue;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return ['rows' => $rows, 'invalid' => $invalid];
    }

    /**
     * @param  list<string|null>  $fields
     */
    private static function isBlankRow(array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string|null>  $fields
     * @return array<string, int>|null
     */
    private static function headerMap(array $fields): ?array
    {
        $map = [];
        foreach ($fields as $index => $field) {
            $key = strtolower(trim((string) $field));
            $key = str_replace([' ', '-'], '_', $key);
            if ($key === 'lead_status' || $key === 'status') {
                $map['status'] = $index;
            } elseif ($key === 'name' || $key === 'contact' || $key === 'contact_name') {
                $map['name'] = $index;
            } elseif ($key === 'company' || $key === 'company_name') {
                $map['company'] = $index;
            } elseif ($key === 'email' || $key === 'email_address') {
                $map['email'] = $index;
            } elseif ($key === 'lead_source' || $key === 'source' || $key === 'referral') {
                $map['source'] = $index;
            } elseif ($key === 'last_activity_time' || $key === 'last_activity' || $key === 'activity') {
                $map['activity'] = $index;
            }
        }

        if (! isset($map['email'])) {
            return null;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private static function defaultMap(): array
    {
        return [
            'status' => 0,
            'name' => 1,
            'company' => 2,
            'email' => 3,
            'source' => 4,
            'activity' => 5,
        ];
    }

    /**
     * @param  array<string, int>  $map
     * @param  list<string|null>  $fields
     * @return array{name: string, company_name: string, email: string, referral: string, status: string, last_activity: string|null}|null
     */
    private static function rowFromFields(array $map, array $fields): ?array
    {
        $email = self::emailAt($fields, $map['email'] ?? null);
        if ($email === null) {
            return null;
        }

        $name = self::cell($fields, $map['name'] ?? null);
        $company = self::cell($fields, $map['company'] ?? null);
        if ($company === '') {
            $company = $name !== '' ? $name : $email;
        }

        return [
            'name' => $name,
            'company_name' => $company,
            'email' => $email,
            'referral' => self::referralFromSource(self::cell($fields, $map['source'] ?? null)),
            'status' => self::statusFromLabel(self::cell($fields, $map['status'] ?? null)),
            'last_activity' => self::nullable(self::cell($fields, $map['activity'] ?? null)),
        ];
    }

    /**
     * @param  list<string|null>  $fields
     * @return array{name: string, company_name: string, email: string, referral: string, status: string, last_activity: string|null}|null
     */
    private static function rowFromEmailAnchor(array $fields): ?array
    {
        $emailIndex = null;
        foreach ($fields as $index => $field) {
            if (self::normalizeEmail((string) $field) !== null) {
                $emailIndex = $index;
                break;
            }
        }
        if ($emailIndex === null) {
            return null;
        }

        $map = [
            'email' => $emailIndex,
            'source' => $emailIndex + 1,
            'activity' => $emailIndex + 2,
        ];
        $before = array_slice($fields, 0, $emailIndex);
        $status = '';
        if (count($before) > 0) {
            $status = trim((string) array_shift($before));
        }
        $company = '';
        $name = '';
        if (count($before) === 1) {
            $name = trim((string) $before[0]);
            $company = $name;
        } elseif (count($before) > 1) {
            $company = trim((string) array_pop($before));
            $name = trim(implode(', ', array_map('strval', $before)));
        }

        $row = self::rowFromFields($map, $fields);
        if ($row === null) {
            return null;
        }
        $row['status'] = self::statusFromLabel($status);
        $row['name'] = $name;
        if ($company !== '') {
            $row['company_name'] = $company;
        }

        return $row;
    }

    /**
     * @param  list<string|null>  $fields
     */
    private static function emailAt(array $fields, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $fields)) {
            return null;
        }

        return self::normalizeEmail((string) $fields[$index]);
    }

    private static function normalizeEmail(string $value): ?string
    {
        $email = strtolower(trim($value));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * @param  list<string|null>  $fields
     */
    private static function cell(array $fields, ?int $index): string
    {
        if ($index === null || ! array_key_exists($index, $fields)) {
            return '';
        }

        return trim((string) $fields[$index]);
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function statusFromLabel(string $label): string
    {
        $value = strtolower(trim($label));
        $value = str_replace([' ', '-'], '_', $value);
        if (in_array($value, Lead::STATUSES, true)) {
            return $value;
        }

        return Lead::STATUS_OLD_LIST;
    }

    private static function referralFromSource(string $source): string
    {
        $value = strtolower($source);
        if (strpos($value, 'google') !== false) {
            return Lead::REFERRAL_GOOGLE;
        }

        return Lead::REFERRAL_BIZY;
    }
}
