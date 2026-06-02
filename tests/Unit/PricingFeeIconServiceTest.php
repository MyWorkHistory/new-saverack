<?php

namespace Tests\Unit;

use App\Services\PricingFeeIconService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PricingFeeIconServiceTest extends TestCase
{
    public function test_public_url_returns_relative_storage_path_not_app_url(): void
    {
        Storage::fake('public');
        $relative = 'pricing-fee-icons/1/test.png';
        Storage::disk('public')->put($relative, 'fake');

        config(['app.url' => 'http://localhost:8000']);

        $url = (new PricingFeeIconService())->publicUrl($relative);

        $this->assertSame('/storage/pricing-fee-icons/1/test.png', $url);
        $this->assertStringNotContainsString('localhost', (string) $url);
    }

    public function test_public_url_returns_null_when_file_missing(): void
    {
        Storage::fake('public');

        $this->assertNull((new PricingFeeIconService())->publicUrl('pricing-fee-icons/missing.png'));
    }
}
