<?php

namespace Tests\Unit;

use App\Support\PortalOnboardingSectionRegistry;
use PHPUnit\Framework\TestCase;

class PortalOnboardingSectionRegistryTest extends TestCase
{
    public function test_branding_section_complete_when_required_fields_present(): void
    {
        $data = [
            'brand_name' => 'Acme',
            'branded_packaging' => 'no',
            'custom_inserts' => 'no',
        ];

        $this->assertTrue(
            PortalOnboardingSectionRegistry::isSectionComplete('branding_information', $data)
        );
    }

    public function test_packing_slip_note_required_when_include_note_yes(): void
    {
        $incomplete = [
            'include_packing_slips' => 'yes',
            'include_brand_logo' => 'yes',
            'show_product_pricing' => 'no',
            'include_support_phone' => 'yes',
            'include_note' => 'yes',
            'packing_slip_note' => '',
        ];

        $this->assertFalse(
            PortalOnboardingSectionRegistry::isSectionComplete('packing_slips_preferences', $incomplete)
        );

        $complete = $incomplete;
        $complete['packing_slip_note'] = 'Thank you for your order!';

        $this->assertTrue(
            PortalOnboardingSectionRegistry::isSectionComplete('packing_slips_preferences', $complete)
        );
    }

    public function test_order_handling_uses_hold_until_approved_label_key(): void
    {
        $sanitized = PortalOnboardingSectionRegistry::sanitizeSectionInput('order_handling_preferences', [
            'order_shipment_timeline' => 'hold_until_approved',
            'multi_warehouse_routing' => 'import_save_rack_only',
        ]);

        $this->assertSame('hold_until_approved', $sanitized['order_shipment_timeline']);
        $this->assertTrue(
            PortalOnboardingSectionRegistry::isSectionComplete('order_handling_preferences', $sanitized)
        );
    }
}
