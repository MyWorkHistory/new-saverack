<?php

namespace Tests\Unit;

use App\Support\AsnDisplay;
use App\Support\InventoryAdjustmentActor;
use App\Models\User;
use Tests\TestCase;

class AsnDisplayTest extends TestCase
{
    public function test_asn_display_label_formats_number(): void
    {
        $this->assertSame('ASN #0020', AsnDisplay::label('0020'));
        $this->assertSame('ASN #0020', AsnDisplay::label('ASN-0020'));
    }

    public function test_inventory_adjustment_actor_appends_user_name(): void
    {
        $user = new User(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $reason = InventoryAdjustmentActor::reasonWithActor('Received from ASN #0020', $user);

        $this->assertSame('Received from ASN #0020 (Jane Doe)', $reason);
    }
}
