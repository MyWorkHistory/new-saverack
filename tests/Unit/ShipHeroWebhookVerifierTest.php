<?php

namespace Tests\Unit;

use App\Services\ShipHeroWebhookVerifier;
use Tests\TestCase;

class ShipHeroWebhookVerifierTest extends TestCase
{
    public function test_verify_accepts_valid_hmac(): void
    {
        $secret = 'test-shared-secret';
        $payload = '{"webhook_type":"Shipment Update","order_uuid":"T3JkZXI6MQ=="}';
        $signature = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        $verifier = new ShipHeroWebhookVerifier();
        $this->assertTrue($verifier->verify($payload, $signature, $secret));
    }

    public function test_verify_rejects_invalid_hmac(): void
    {
        $verifier = new ShipHeroWebhookVerifier();
        $this->assertFalse($verifier->verify('{}', 'bad-signature', 'secret'));
    }
}
