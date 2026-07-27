<?php

namespace Tests\Unit;

use App\Support\LeadQuickAddParser;
use PHPUnit\Framework\TestCase;

class LeadQuickAddParserTest extends TestCase
{
    public function test_parses_company_website_email_and_thread(): void
    {
        $text = <<<'TEXT'
Company: Blue Ridge Exotics
Website: blueridgeexotics.com
Email: sales@blueridgeexotics.com

Email Thread:
Can you send over some details on what this would look like for us?
TEXT;

        $parsed = LeadQuickAddParser::parse($text);

        $this->assertSame('Blue Ridge Exotics', $parsed['company_name']);
        $this->assertSame('blueridgeexotics.com', $parsed['website']);
        $this->assertSame('sales@blueridgeexotics.com', $parsed['email']);
        $this->assertSame(
            'Can you send over some details on what this would look like for us?',
            $parsed['comment']
        );
    }

    public function test_parses_multiline_email_thread(): void
    {
        $text = <<<'TEXT'
Company: Acme Co
Email: hello@acme.test

Email Thread:
Line one
Line two
TEXT;

        $parsed = LeadQuickAddParser::parse($text);

        $this->assertSame("Line one\nLine two", $parsed['comment']);
        $this->assertNull($parsed['website']);
    }
}
