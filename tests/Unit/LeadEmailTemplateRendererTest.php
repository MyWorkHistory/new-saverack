<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Support\LeadEmailTemplateRenderer;
use Tests\TestCase;

final class LeadEmailTemplateRendererTest extends TestCase
{
    public function test_replaces_placeholders_case_insensitive_in_subject_and_body(): void
    {
        $lead = new Lead([
            'name' => 'Alex',
            'company_name' => 'Blue Ridge Exotics',
            'website' => 'blueridgeexotics.com',
            'email' => 'sales@blueridge.com',
        ]);

        $subject = LeadEmailTemplateRenderer::renderSubject(
            'Hi {Name} at {company}',
            $lead
        );
        $this->assertSame('Hi Alex at Blue Ridge Exotics', $subject);

        $body = LeadEmailTemplateRenderer::renderBody(
            '<p>Thanks for reaching out from {COMPANY}. Visit {website} or reply to {email}.</p>',
            $lead
        );
        $this->assertSame(
            '<p>Thanks for reaching out from Blue Ridge Exotics. Visit blueridgeexotics.com or reply to sales@blueridge.com.</p>',
            $body
        );
    }

    public function test_unknown_placeholder_is_left_unchanged(): void
    {
        $lead = new Lead([
            'company_name' => 'Acme',
            'email' => 'a@example.com',
        ]);

        $text = LeadEmailTemplateRenderer::renderSubject('Hello {Foo}', $lead);
        $this->assertSame('Hello {Foo}', $text);
    }

    public function test_body_escapes_html_in_lead_fields(): void
    {
        $lead = new Lead([
            'name' => '<script>alert(1)</script>',
            'company_name' => 'Safe Co',
            'email' => 'safe@example.com',
        ]);

        $body = LeadEmailTemplateRenderer::renderBody('<p>Hi {Name}</p>', $lead);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }
}
