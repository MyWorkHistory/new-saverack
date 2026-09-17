<?php

namespace App\Support;

/**
 * Starter copy for the Old List email template. Edit it on the templates page.
 */
final class OldListLeadCatalog
{
    public const TEMPLATE_NAME = 'Old List';

    public static function templateSubject(): string
    {
        return 'Following up with {Company}';
    }

    public static function templateBody(): string
    {
        return '<p>Hi {Name},</p>'
            .'<p>I am reaching back out about fulfillment for {Company}. We help brands with storage, pick, and pack, and I wanted to see if that is still useful for you.</p>'
            .'<p>If you want a quick look at pricing, reply to this email and I will send it over.</p>'
            .'<p>Thanks,<br>Save Rack</p>';
    }
}
