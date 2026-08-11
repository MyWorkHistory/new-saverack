<?php

namespace App\Services;

class ShopifyWebhookVerifier
{
    public function verify(string $rawBody, string $hmacHeader, string $secret): bool
    {
        $secret = trim($secret);
        $hmacHeader = trim($hmacHeader);
        if ($secret === '' || $hmacHeader === '') {
            return false;
        }

        $digest = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($digest, $hmacHeader);
    }
}
