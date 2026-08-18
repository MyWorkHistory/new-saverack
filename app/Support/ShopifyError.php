<?php

namespace App\Support;

class ShopifyError
{
    public static function staffMessage(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $raw;
        }

        if (self::isProtectedOrderAccess($raw)) {
            return 'Shopify blocked order import because this app is not approved for Protected Customer Data (Orders). '
                .'In Partner Dashboard → Apps → this app → API access requests → Protected customer data, '
                .'enable Protected customer data and Orders (plus Name, Address, Email, Phone if needed), then Save. '
                .'On a development store Shopify review is not required. '
                .'Click Connect With Shopify again, then Full Re-Import. Catalog is already synced.';
        }

        return $raw;
    }

    public static function isProtectedOrderAccess(string $raw): bool
    {
        $lower = strtolower($raw);

        return str_contains($lower, 'not approved to access the order object')
            || str_contains($lower, 'protected-customer-data')
            || str_contains($lower, 'protected customer data');
    }
}
