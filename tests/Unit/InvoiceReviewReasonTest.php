<?php

namespace Tests\Unit;

use App\Support\InvoiceReviewReason;
use Tests\TestCase;

final class InvoiceReviewReasonTest extends TestCase
{
    public function test_all_keys_have_labels(): void
    {
        foreach (InvoiceReviewReason::keys() as $key) {
            $label = InvoiceReviewReason::label($key);
            $this->assertNotSame('', trim($label));
            $this->assertSame($label, InvoiceReviewReason::options()[$key]);
        }
    }

    public function test_unknown_key_returns_key_as_label(): void
    {
        $this->assertSame('unknown_reason', InvoiceReviewReason::label('unknown_reason'));
    }
}
