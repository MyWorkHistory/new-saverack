<?php

namespace Tests\Unit;

use App\Models\PricingFeeTemplate;
use PHPUnit\Framework\TestCase;

class PricingFeeCategoryVisibilityTest extends TestCase
{
    public function test_postage_is_account_schedule_but_not_client_visible(): void
    {
        $this->assertTrue(PricingFeeTemplate::isAccountScheduleCategory(PricingFeeTemplate::CATEGORY_POSTAGE));
        $this->assertFalse(PricingFeeTemplate::isClientVisibleCategory(PricingFeeTemplate::CATEGORY_POSTAGE));
    }

    public function test_fulfillment_is_both_client_visible_and_account_schedule(): void
    {
        $this->assertTrue(PricingFeeTemplate::isAccountScheduleCategory(PricingFeeTemplate::CATEGORY_FULFILLMENT));
        $this->assertTrue(PricingFeeTemplate::isClientVisibleCategory(PricingFeeTemplate::CATEGORY_FULFILLMENT));
    }

    public function test_client_visible_categories_exclude_postage(): void
    {
        $this->assertNotContains(
            PricingFeeTemplate::CATEGORY_POSTAGE,
            PricingFeeTemplate::CLIENT_VISIBLE_CATEGORIES
        );
        $this->assertContains(
            PricingFeeTemplate::CATEGORY_POSTAGE,
            PricingFeeTemplate::CATEGORIES
        );
    }
}
