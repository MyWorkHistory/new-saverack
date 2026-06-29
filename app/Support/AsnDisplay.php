<?php

namespace App\Support;

class AsnDisplay
{
    public static function label(string $asnNumber): string
    {
        $s = trim($asnNumber);
        if ($s === '') {
            return '';
        }
        $stripped = preg_replace('/^ASN[#\s-]*/i', '', $s);
        $stripped = trim((string) $stripped);

        return $stripped !== '' ? 'ASN #'.$stripped : 'ASN #'.$s;
    }
}
