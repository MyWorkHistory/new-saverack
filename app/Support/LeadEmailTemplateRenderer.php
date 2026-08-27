<?php

namespace App\Support;

use App\Models\Lead;

/**
 * Replace {Name}, {Company}, {website}, etc. in lead template email subject/body.
 */
final class LeadEmailTemplateRenderer
{
    /**
     * @return list<array{token: string, label: string}>
     */
    public static function placeholderHelp(): array
    {
        return [
            ['token' => '{Name}', 'label' => 'Contact name'],
            ['token' => '{Company}', 'label' => 'Company name'],
            ['token' => '{Website}', 'label' => 'Website URL'],
            ['token' => '{Email}', 'label' => 'Lead email address'],
        ];
    }

    public static function renderSubject(string $subject, Lead $lead): string
    {
        return self::replace($subject, self::valuesForLead($lead, false));
    }

    public static function renderBody(string $bodyHtml, Lead $lead): string
    {
        return self::replace($bodyHtml, self::valuesForLead($lead, true));
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function replace(string $text, array $values): string
    {
        if ($text === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            function (array $matches) use ($values) {
                $key = strtolower((string) ($matches[1] ?? ''));

                return array_key_exists($key, $values) ? $values[$key] : (string) ($matches[0] ?? '');
            },
            $text
        );
    }

    /**
     * @return array<string, string>
     */
    private static function valuesForLead(Lead $lead, bool $escapeHtml): array
    {
        $name = trim((string) ($lead->name ?? ''));
        $company = trim((string) ($lead->company_name ?? ''));
        $website = trim((string) ($lead->website ?? ''));
        $email = trim((string) ($lead->email ?? ''));

        $format = function (string $value) use ($escapeHtml): string {
            return $escapeHtml ? e($value) : $value;
        };

        return [
            'name' => $format($name),
            'contact' => $format($name),
            'company' => $format($company),
            'companyname' => $format($company),
            'website' => $format($website),
            'url' => $format($website),
            'site' => $format($website),
            'email' => $format($email),
        ];
    }
}
