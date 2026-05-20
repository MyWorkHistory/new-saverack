<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ShipHero 3PL customer account provisioning (schema probe + optional create).
 */
class ShipHeroCustomerAccountService
{
    /** @var ShipHeroClient */
    protected $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * Introspect mutation field names (dev / artisan probe).
     *
     * @return list<string>
     */
    public function listMutationNames(): array
    {
        $graphql = <<<'GQL'
query ShipHeroSchemaMutations {
  __schema {
    mutationType {
      fields {
        name
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, []);
        } catch (\Throwable $e) {
            Log::warning('shiphero.customer_account.schema_probe_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $fields = data_get($json, 'data.__schema.mutationType.fields');
        if (! is_array($fields)) {
            return [];
        }

        $names = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = isset($field['name']) && is_string($field['name']) ? trim($field['name']) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Mutation names that might create a 3PL customer account.
     *
     * @return list<string>
     */
    public function findCustomerAccountCreateMutations(): array
    {
        $needles = ['customer', 'account', '3pl', 'invite', 'client'];
        $out = [];
        foreach ($this->listMutationNames() as $name) {
            $lower = strtolower($name);
            foreach ($needles as $needle) {
                if (strpos($lower, $needle) !== false) {
                    $out[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Attempt to create a ShipHero customer account via API when a supported mutation exists.
     *
     * @return string|null GraphQL customer_account_id, or null if unavailable / failed
     */
    public function tryCreateCustomerAccount(
        string $companyName,
        string $contactName,
        string $email,
        string $phone
    ): ?string {
        $candidates = $this->findCustomerAccountCreateMutations();
        if ($candidates === []) {
            Log::info('shiphero.customer_account.create_skipped', [
                'reason' => 'no_candidate_mutations_in_schema',
            ]);

            return null;
        }

        Log::info('shiphero.customer_account.create_candidates', [
            'mutations' => $candidates,
        ]);

        return null;
    }
}
