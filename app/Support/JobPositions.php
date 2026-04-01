<?php

namespace App\Support;

final class JobPositions
{
    /** @return list<string> */
    public static function allowed(): array
    {
        return [
            'Picker & Packer',
            'Receiving',
            'Inventory',
            'Account Manager',
            'Account Sr. Manager',
            'Accounting',
            'Operations Manager',
        ];
    }
}
