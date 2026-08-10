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
        $this->assertNull($parsed['name']);
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

    public function test_parses_google_format(): void
    {
        $text = <<<'TEXT'
Full Name	:	Cherrie, Deas
Company Name	:	TALAA LLC
Email	:	Info@rheeboutique.com
Phone Number	:	8136679100
Store Website URL	:	rheeboutique.com
Tell us about any special requirements	:	Hello.

Subject: 3PL Partnership Inquiry – Upcoming Launch

Are you currently accepting new startup brands?

My name is Cherrie Deas.
TEXT;

        $parsed = LeadQuickAddParser::parse($text, 'google');

        $this->assertSame('Cherrie Deas', $parsed['name']);
        $this->assertSame('TALAA LLC', $parsed['company_name']);
        $this->assertSame('Info@rheeboutique.com', $parsed['email']);
        $this->assertSame('rheeboutique.com', $parsed['website']);
        $this->assertStringContainsString('Phone: 8136679100', (string) $parsed['comment']);
        $this->assertStringContainsString('Hello.', (string) $parsed['comment']);
        $this->assertStringContainsString('Subject: 3PL Partnership Inquiry', (string) $parsed['comment']);
        $this->assertStringContainsString('Are you currently accepting new startup brands?', (string) $parsed['comment']);
    }
}
