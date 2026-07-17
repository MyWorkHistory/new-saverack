<?php

namespace App\Support;

use App\Models\ClientAccount;
use Carbon\Carbon;

class FulfillmentAgreementPreamble
{
    public static function html(ClientAccount $account, ?Carbon $effectiveDate = null): string
    {
        $date = $account->fulfillment_agreement_client_signed_at
            ? Carbon::parse($account->fulfillment_agreement_client_signed_at)
            : ($effectiveDate ?: now());

        $company = self::valueOrPlaceholder($account->company_name, '[CLIENT COMPANY NAME]');
        $state = self::valueOrPlaceholder($account->state, '[STATE]');
        $address = self::address($account);

        return '<div class="fulfillment-agreement-preamble">'
            .'<p>This Fulfillment Services Agreement (&quot;Agreement&quot;) is entered into and made effective as of <strong>'
            .self::escape($date->format('F j, Y'))
            .'</strong> (&quot;Effective Date&quot;), by and between:</p>'
            .'<p><strong>Save Rack LLC</strong>, a Florida Limited Liability Company and third-party logistics provider '
            .'(&quot;Save Rack&quot; or &quot;3PL&quot;), with its principal place of business located at '
            .'3135 Drane Field Rd #21, Lakeland, FL 33811;</p>'
            .'<p>and</p>'
            .'<p><strong>'.$company.'</strong>, a <strong>'.$state.'</strong> entity (&quot;Client&quot;), '
            .'with its principal place of business located at <strong>'.$address.'</strong>.</p>'
            .'<p>Save Rack and Client may individually be referred to herein as a &quot;Party&quot; '
            .'and collectively as the &quot;Parties.&quot;</p>'
            .'</div>';
    }

    private static function address(ClientAccount $account): string
    {
        $street = trim((string) $account->street);
        $city = trim((string) $account->city);
        $state = trim((string) $account->state);
        $zip = trim((string) $account->zip);

        $cityStateZip = $city;
        if ($state !== '') {
            $cityStateZip .= ($cityStateZip !== '' ? ', ' : '').$state;
        }
        if ($zip !== '') {
            $cityStateZip .= ($cityStateZip !== '' ? ' ' : '').$zip;
        }

        $address = trim(implode(' ', array_filter([$street, $cityStateZip], static function ($value) {
            return $value !== '';
        })));

        return $address !== '' ? self::escape($address) : '[ADDRESS]';
    }

    private static function valueOrPlaceholder($value, string $placeholder): string
    {
        $value = trim((string) $value);

        return $value !== '' ? self::escape($value) : $placeholder;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
