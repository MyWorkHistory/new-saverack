<?php

namespace App\Services;

use RuntimeException;

class ShipHeroWebhookRegistrationService
{
    /** @var list<string> */
    public const ORDER_WEBHOOK_NAMES = [
        'Shipment Update',
        'Order Canceled',
        'Order Allocated',
        'Order Deallocated',
        'Order Packed Out',
    ];

    /** @var ShipHeroClient */
    private $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRegisteredWebhooks(): array
    {
        $graphql = <<<'GQL'
query ShipHeroWebhooks {
  webhooks {
    request_id
    data {
      edges {
        node {
          id
          name
          url
          shop_name
          enabled
          health
        }
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql);
        $edges = data_get($json, 'data.webhooks.data.edges', []);
        if (! is_array($edges)) {
            return [];
        }

        $rows = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }
            $rows[] = $node;
        }

        return $rows;
    }

    /**
     * @return array{created: list<string>, skipped: list<string>, secrets: list<array{name: string, secret: string}>}
     */
    public function registerOrderWebhooks(string $url, string $shopName = 'saverack'): array
    {
        $url = trim($url);
        $shopName = trim($shopName);
        if ($url === '') {
            throw new RuntimeException('Webhook URL is required.');
        }
        if ($shopName === '') {
            throw new RuntimeException('Webhook shop_name is required.');
        }

        $existing = $this->listRegisteredWebhooks();
        $existingKeys = [];
        foreach ($existing as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $rowUrl = trim((string) ($row['url'] ?? ''));
            $rowShop = trim((string) ($row['shop_name'] ?? ''));
            if ($name !== '') {
                $existingKeys[$name.'|'.$rowShop.'|'.$rowUrl] = true;
            }
        }

        $created = [];
        $skipped = [];
        $secrets = [];

        foreach (self::ORDER_WEBHOOK_NAMES as $name) {
            $key = $name.'|'.$shopName.'|'.$url;
            if (isset($existingKeys[$key])) {
                $skipped[] = $name;
                continue;
            }

            $graphql = <<<'GQL'
mutation ShipHeroWebhookCreate($data: CreateWebhookInput!) {
  webhook_create(data: $data) {
    request_id
    webhook {
      id
      name
      url
      shop_name
      enabled
      shared_signature_secret
    }
  }
}
GQL;

            $data = [
                'name' => $name,
                'url' => $url,
                'shop_name' => $shopName,
            ];
            $customerAccountId = trim((string) config('services.shiphero.webhook_customer_account_id', ''));
            if ($customerAccountId !== '') {
                $data['customer_account_id'] = $customerAccountId;
            }

            $json = $this->client->query($graphql, [
                'data' => $data,
            ]);

            $webhook = data_get($json, 'data.webhook_create.webhook');
            if (! is_array($webhook)) {
                throw new RuntimeException('ShipHero did not return webhook_create payload for '.$name.'.');
            }

            $created[] = $name;
            $secret = trim((string) ($webhook['shared_signature_secret'] ?? ''));
            if ($secret !== '') {
                $secrets[] = ['name' => $name, 'secret' => $secret];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'secrets' => $secrets,
        ];
    }
}
