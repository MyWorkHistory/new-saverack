<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_allows_formatting_and_strips_scripts(): void
    {
        $html = '<h2>Title</h2><p>Hello <strong>world</strong></p><ul><li>One</li></ul><script>alert(1)</script><img src=x onerror=alert(1)>';
        $clean = HtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('<strong>world</strong>', $clean);
        $this->assertStringContainsString('<ul><li>One</li></ul>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }
}
