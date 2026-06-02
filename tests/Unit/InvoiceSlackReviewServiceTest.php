<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceSlackReviewService;
use App\Support\InvoiceReviewReason;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InvoiceSlackReviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.slack.bot_token' => 'xoxb-test-token',
            'billing.slack.accounting_channel' => '#accounting',
            'crm.frontend_url' => 'https://app.saverack.com',
        ]);
    }

    public function test_build_message_includes_reason_and_view_invoice_link(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Spirit Nest',
            'email' => 'spirit@test.com',
        ]);

        $invoice = new Invoice([
            'invoice_number' => '633947',
            'status' => 'open',
        ]);
        $invoice->id = 517;
        $invoice->setRelation('clientAccount', $account);

        $text = app(InvoiceSlackReviewService::class)->buildMessageText(
            $invoice,
            InvoiceReviewReason::HIGH_POSTAGE,
            null,
        );

        $this->assertStringContainsString('*Invoice Review*', $text);
        $this->assertStringContainsString('Invoice #633947 - Spirit Nest - High Postage', $text);
        $this->assertStringNotContainsString('Note:', $text);
        $this->assertStringContainsString(
            '<https://app.saverack.com/admin/billing/invoices/517|View Invoice>',
            $text
        );
    }

    public function test_build_message_includes_note_when_provided(): void
    {
        $account = new ClientAccount(['company_name' => 'Acme Co']);
        $invoice = new Invoice(['invoice_number' => 'INV-1']);
        $invoice->id = 10;
        $invoice->setRelation('clientAccount', $account);

        $text = app(InvoiceSlackReviewService::class)->buildMessageText(
            $invoice,
            InvoiceReviewReason::MISSING_FEES,
            'Please verify line items.',
        );

        $this->assertStringContainsString('Note: Please verify line items.', $text);
    }

    public function test_post_review_calls_slack_api(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200),
        ]);

        $account = new ClientAccount(['company_name' => 'Test Co']);
        $invoice = new Invoice(['invoice_number' => '100', 'status' => 'open']);
        $invoice->id = 1;
        $invoice->setRelation('clientAccount', $account);

        app(InvoiceSlackReviewService::class)->postReview(
            $invoice,
            InvoiceReviewReason::OTHER_CHARGES,
            'Check totals',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && ($body['channel'] ?? '') === '#accounting'
                && str_contains((string) ($body['text'] ?? ''), '*Invoice Review*')
                && str_contains((string) ($body['text'] ?? ''), 'Note: Check totals');
        });
    }

    public function test_post_review_throws_when_slack_returns_error(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found'], 200),
        ]);

        $invoice = new Invoice(['invoice_number' => '100', 'status' => 'open']);
        $invoice->id = 1;
        $invoice->setRelation('clientAccount', new ClientAccount(['company_name' => 'Co']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Slack rejected');

        app(InvoiceSlackReviewService::class)->postReview(
            $invoice,
            InvoiceReviewReason::OTHER_CHARGES,
            null,
        );
    }
}
