<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class BillCreatedSlackService
{
    private const USERNAME = 'Bill Created';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * Post to #billing when a custom bill is created.
     * Failures are logged and do not block bill creation.
     */
    public function notifyCustomBill(CustomBill $bill): void
    {
        $bill->loadMissing('clientAccount');
        $this->notify(
            $this->accountName($bill->clientAccount),
            (int) $bill->total_cents,
            (string) $bill->bill_number,
            CrmUrls::customBillStaffUrl((int) $bill->id),
            ['bill_type' => 'custom', 'bill_id' => (int) $bill->id]
        );
    }

    /**
     * @return array{text: string, username: string}
     */
    public function buildMessagePayload(string $accountName, int $totalCents, string $billNumber, string $billUrl): array
    {
        $amount = $this->formatUsd($totalCents);
        $number = trim($billNumber) !== '' ? trim($billNumber) : '—';

        $lines = [
            'Account: '.$accountName.' - '.$amount,
            '<'.$billUrl.'|Bill #'.$number.' - View Bill>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function notify(string $accountName, int $totalCents, string $billNumber, string $billUrl, array $context = []): void
    {
        $payload = $this->buildMessagePayload($accountName, $totalCents, $billNumber, $billUrl);
        $channel = trim((string) (config('billing.slack.billing_channel') ?: '#billing'));
        if ($channel === '') {
            $channel = '#billing';
        }

        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? self::USERNAME);
        $options = $this->deliveryOptions($username);

        try {
            $result = $this->slack->post(
                $channel,
                $text,
                (string) ($options['username'] ?? $username),
                $options['slack'] ?? []
            );
            Log::info('billing.bill_created_slack_sent', array_merge($context, [
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
            ]));
        } catch (\Throwable $e) {
            Log::warning('billing.bill_created_slack_failed', array_merge($context, [
                'slack_channel' => $channel,
                'message' => $e->getMessage(),
            ]));
        }
    }

    private function accountName(?ClientAccount $account): string
    {
        if ($account === null) {
            return '—';
        }
        $name = trim((string) ($account->company_name ?? ''));

        return $name !== '' ? $name : '—';
    }

    private function formatUsd(int $cents): string
    {
        return '$'.number_format($cents / 100, 2, '.', ',');
    }

    /**
     * @return array{username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $username): array
    {
        $slack = [];
        if ($this->slack->hasBotToken()) {
            $slack['customize_identity'] = true;
            $slack['prefer_bot'] = true;
        }

        return [
            'username' => $username,
            'slack' => $slack,
        ];
    }
}
