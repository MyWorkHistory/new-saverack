<?php

namespace App\Services;

class ShipHeroWebhookVerifier
{
    public function verify(string $payload, string $signatureHeader, string $secret): bool
    {
        $secret = trim($secret);
        $signatureHeader = trim($signatureHeader);
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $digest = hash_hmac('sha256', $payload, $secret, true);
        $calculated = base64_encode($digest);

        return hash_equals($calculated, $signatureHeader);
    }
}
