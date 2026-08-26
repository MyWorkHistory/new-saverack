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

    public function test_prefers_variant_image_over_product(): void
    {
        $this->assertSame(
            'https://cdn.example/variant.png',
            ShopifyProductImage::url(
                ['image' => ['url' => 'https://cdn.example/variant.png']],
                ['featuredImage' => ['url' => 'https://cdn.example/product.png']]
            )
        );
    }

    public function test_falls_back_to_product_when_variant_has_no_image(): void
    {
        $this->assertSame(
            'https://cdn.example/product.png',
            ShopifyProductImage::url(
                ['sku' => 'ABC'],
                ['featuredImage' => ['url' => 'https://cdn.example/product.png']]
            )
        );
    }

    public function test_reads_rest_images_array(): void
    {
        $this->assertSame(
            'https://cdn.example/rest.jpg',
            ShopifyProductImage::url([
                'images' => [['src' => 'https://cdn.example/rest.jpg']],
            ])
        );
    }
}
