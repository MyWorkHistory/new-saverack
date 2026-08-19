<?php

namespace Tests\Unit;

use App\Support\ShopifyProductImage;
use Tests\TestCase;

class ShopifyProductImageTest extends TestCase
{
    public function test_reads_featured_image_url(): void
    {
        $this->assertSame(
            'https://cdn.example/a.png',
            ShopifyProductImage::url([
                'featuredImage' => ['url' => 'https://cdn.example/a.png'],
            ])
        );
    }
}
