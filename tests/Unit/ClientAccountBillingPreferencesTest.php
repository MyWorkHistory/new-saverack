<?php

namespace Tests\Unit;

use App\Support\ClientAccountBillingPreferences;
use Tests\TestCase;

final class ClientAccountBillingPreferencesTest extends TestCase
{
    public function test_default_postage_and_packaging_labels(): void
    {
        $this->assertSame(
            'Save Rack Provides All Postage',
            ClientAccountBillingPreferences::postageLabel(null)
        );
        $this->assertSame(
            'Save Rack Provides All Packaging Materials',
            ClientAccountBillingPreferences::packagingLabel(null)
        );
    }

    public function test_all_postage_and_packaging_keys_have_labels(): void
    {
        foreach (ClientAccountBillingPreferences::postageKeys() as $key) {
            $label = ClientAccountBillingPreferences::postageLabel($key);
            $this->assertNotSame('', trim($label));
            $this->assertSame($label, ClientAccountBillingPreferences::postageOptions()[$key]);
        }

        foreach (ClientAccountBillingPreferences::packagingKeys() as $key) {
            $label = ClientAccountBillingPreferences::packagingLabel($key);
            $this->assertNotSame('', trim($label));
            $this->assertSame($label, ClientAccountBillingPreferences::packagingOptions()[$key]);
        }
    }

    public function test_invalid_keys_fall_back_to_defaults(): void
    {
        $this->assertSame(
            ClientAccountBillingPreferences::defaultPostageKey(),
            ClientAccountBillingPreferences::normalizePostageKey('not_a_real_key')
        );
        $this->assertSame(
            ClientAccountBillingPreferences::defaultPackagingKey(),
            ClientAccountBillingPreferences::normalizePackagingKey('not_a_real_key')
        );
        $this->assertSame(
            'Save Rack Provides All Postage',
            ClientAccountBillingPreferences::postageLabel('not_a_real_key')
        );
        $this->assertSame(
            'Save Rack Provides All Packaging Materials',
            ClientAccountBillingPreferences::packagingLabel('not_a_real_key')
        );
    }
}
