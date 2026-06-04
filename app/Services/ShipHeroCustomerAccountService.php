<?php

namespace App\Services;

use App\Models\ClientAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ShipHero 3PL customer account provisioning, schema probe, and hide-orders sync.
 */
class ShipHeroCustomerAccountService
{
    private const CACHE_HIDE_ORDERS_CONFIG = 'shiphero.customer_account.hide_orders_sync_config';

    /** @var ShipHeroClient */
    protected $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    public function shouldHideOrdersFromApp(string $status): bool
    {
        return strtolower(trim($status)) !== ClientAccount::STATUS_ACTIVE;
    }

    /**
     * Sync ShipHero "Hide Customer's Orders From App" from CRM account status.
     *
     * @return array{ok: bool, message: string|null}
     */
    public function syncHideOrdersFromApp(ClientAccount $account): array
    {
        $shipheroId = trim((string) $account->shiphero_customer_account_id);
        if ($shipheroId === '') {
            return ['ok' => true, 'message' => 'skipped — no ShipHero customer account ID'];
        }

        $config = $this->resolveHideOrdersSyncConfig();
        if ($config === null) {
            return [
                'ok' => false,
                'message' => 'ShipHero Public API has no hide-orders mutation configured. Run php artisan shiphero:probe-customer-mutations on the server; if none is found, toggle Hide Customer\'s Orders From App manually in ShipHero → 3PL Customers.',
            ];
        }

        $hide = $this->shouldHideOrdersFromApp((string) $account->status);
        $data = [
            $config['id_field'] => $shipheroId,
            $config['hide_field'] => $hide,
        ];

        try {
            $this->executeHideOrdersMutation($config, $data);
        } catch (\Throwable $e) {
            Log::warning('shiphero.customer_account.hide_orders_sync_failed', [
                'client_account_id' => $account->id,
                'shiphero_customer_account_id' => $shipheroId,
                'hide' => $hide,
                'mutation' => $config['mutation'],
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        Log::info('shiphero.customer_account.hide_orders_synced', [
            'client_account_id' => $account->id,
            'shiphero_customer_account_id' => $shipheroId,
            'hide' => $hide,
            'mutation' => $config['mutation'],
        ]);

        return ['ok' => true, 'message' => null];
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
     * @return list<array{name: string, args: list<array<string, mixed>>}>
     */
    public function listMutationFieldsWithArgs(): array
    {
        $graphql = <<<'GQL'
query ShipHeroSchemaMutationFields {
  __schema {
    mutationType {
      fields {
        name
        args {
          name
          type {
            kind
            name
            ofType {
              kind
              name
              ofType {
                kind
                name
              }
            }
          }
        }
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, []);
        } catch (\Throwable $e) {
            return [];
        }

        $fields = data_get($json, 'data.__schema.mutationType.fields');
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                continue;
            }
            $args = [];
            if (isset($field['args']) && is_array($field['args'])) {
                foreach ($field['args'] as $arg) {
                    if (is_array($arg)) {
                        $args[] = $arg;
                    }
                }
            }
            $out[] = ['name' => $field['name'], 'args' => $args];
        }

        return $out;
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
            $hasCustomer = strpos($lower, 'customer') !== false;
            $hasAccount = strpos($lower, 'account') !== false;
            if (! $hasCustomer && ! $hasAccount) {
                continue;
            }
            foreach ($needles as $needle) {
                if (strpos($lower, $needle) !== false && strpos($lower, 'update') === false) {
                    $out[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Mutation names that might update a 3PL customer account.
     *
     * @return list<string>
     */
    public function findCustomerAccountUpdateMutations(): array
    {
        $out = [];
        foreach ($this->listMutationNames() as $name) {
            $lower = strtolower($name);
            $hasCustomer = strpos($lower, 'customer') !== false;
            $hasAccount = strpos($lower, 'account') !== false;
            $hasRelationship = strpos($lower, 'relationship') !== false;
            if (! $hasCustomer && ! $hasAccount && ! $hasRelationship) {
                continue;
            }
            if (
                strpos($lower, 'update') !== false
                || strpos($lower, 'edit') !== false
                || strpos($lower, 'set') !== false
            ) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{name: string, type_kind: string|null, type_name: string|null}>
     */
    public function introspectInputTypeFields(string $typeName): array
    {
        $graphql = <<<'GQL'
query ShipHeroInputTypeFields($name: String!) {
  __type(name: $name) {
    name
    inputFields {
      name
      type {
        kind
        name
        ofType {
          kind
          name
          ofType {
            kind
            name
          }
        }
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, ['name' => $typeName]);
        } catch (\Throwable $e) {
            return [];
        }

        $inputFields = data_get($json, 'data.__type.inputFields');
        if (! is_array($inputFields)) {
            return [];
        }

        $out = [];
        foreach ($inputFields as $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                continue;
            }
            $type = is_array($field['type'] ?? null) ? $field['type'] : [];
            $out[] = [
                'name' => $field['name'],
                'type_kind' => $this->unwrapGraphQlTypeKind($type),
                'type_name' => $this->unwrapGraphQlTypeName($type),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, type_kind: string|null, type_name: string|null}>
     */
    public function introspectObjectTypeFields(string $typeName): array
    {
        $graphql = <<<'GQL'
query ShipHeroObjectTypeFields($name: String!) {
  __type(name: $name) {
    name
    fields {
      name
      type {
        kind
        name
        ofType {
          kind
          name
        }
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, ['name' => $typeName]);
        } catch (\Throwable $e) {
            return [];
        }

        $fields = data_get($json, 'data.__type.fields');
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                continue;
            }
            $type = is_array($field['type'] ?? null) ? $field['type'] : [];
            $out[] = [
                'name' => $field['name'],
                'type_kind' => $this->unwrapGraphQlTypeKind($type),
                'type_name' => $this->unwrapGraphQlTypeName($type),
            ];
        }

        return $out;
    }

    /**
     * Connectivity + schema diagnostics for artisan probe (no introspection required).
     *
     * @return array{
     *   refresh_token_configured: bool,
     *   token_refresh: array{ok: bool, message?: string},
     *   account_query: array{ok: bool, request_id?: string|null, message?: string},
     *   introspection: array{ok: bool, mutation_count: int, message?: string},
     *   warehouses: list<array{id: string, identifier: string|null}>
     * }
     */
    public function diagnoseApiAccess(): array
    {
        $out = [
            'refresh_token_configured' => trim((string) config('services.shiphero.refresh_token', '')) !== '',
            'token_refresh' => ['ok' => false],
            'account_query' => ['ok' => false],
            'introspection' => ['ok' => false, 'mutation_count' => 0],
            'warehouses' => [],
        ];

        if (! $out['refresh_token_configured']) {
            $out['token_refresh'] = [
                'ok' => false,
                'message' => 'SHIPHERO_REFRESH_TOKEN is empty in .env',
            ];

            return $out;
        }

        try {
            $this->client->accessToken();
            $out['token_refresh'] = ['ok' => true];
        } catch (\Throwable $e) {
            $out['token_refresh'] = ['ok' => false, 'message' => $e->getMessage()];

            return $out;
        }

        try {
            $json = $this->client->query(<<<'GQL'
query ShipHeroProbeAccount {
  account {
    request_id
    data {
      id
      warehouses {
        id
        identifier
      }
    }
  }
}
GQL, []);
            $out['account_query'] = [
                'ok' => data_get($json, 'data.account.request_id') !== null,
                'request_id' => data_get($json, 'data.account.request_id'),
            ];
            $warehouses = data_get($json, 'data.account.data.warehouses');
            if (is_array($warehouses)) {
                foreach ($warehouses as $wh) {
                    if (! is_array($wh)) {
                        continue;
                    }
                    $id = isset($wh['id']) ? trim((string) $wh['id']) : '';
                    if ($id === '') {
                        continue;
                    }
                    $out['warehouses'][] = [
                        'id' => $id,
                        'identifier' => isset($wh['identifier']) ? trim((string) $wh['identifier']) : null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $out['account_query'] = ['ok' => false, 'message' => $e->getMessage()];
        }

        $introspection = $this->introspectMutationNamesWithDiagnostics();
        $out['introspection'] = [
            'ok' => $introspection['ok'],
            'mutation_count' => count($introspection['names']),
            'message' => $introspection['message'],
        ];

        return $out;
    }

    /**
     * @return array{ok: bool, names: list<string>, message: string|null}
     */
    public function introspectMutationNamesWithDiagnostics(): array
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
            return ['ok' => false, 'names' => [], 'message' => $e->getMessage()];
        }

        $fields = data_get($json, 'data.__schema.mutationType.fields');
        if (! is_array($fields)) {
            $errors = data_get($json, 'errors');
            $message = is_array($errors) && isset($errors[0]['message'])
                ? (string) $errors[0]['message']
                : 'Schema introspection returned no mutation fields (often disabled on production tokens).';

            return ['ok' => false, 'names' => [], 'message' => $message];
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

        return ['ok' => true, 'names' => $names, 'message' => null];
    }

    /**
     * Probe whether a mutation name exists without schema introspection.
     *
     * @return array{exists: bool, arg_name: string|null, input_type: string|null, message: string}
     */
    public function probeMutationSignature(string $mutationName): array
    {
        $mutationName = trim($mutationName);
        if ($mutationName === '') {
            return ['exists' => false, 'arg_name' => null, 'input_type' => null, 'message' => 'Empty mutation name'];
        }

        $graphql = 'mutation { '.$mutationName.' { request_id } }';
        $raw = $this->client->queryRawDiagnostic($graphql, []);
        $bodyRaw = is_array($raw) ? (string) ($raw['body'] ?? '') : '';
        $body = json_decode($bodyRaw, true);
        $message = $this->firstGraphQlErrorMessage(is_array($body) ? $body : []);

        if ($message === '' && is_array($body) && data_get($body, 'data.'.$mutationName) !== null) {
            return ['exists' => true, 'arg_name' => null, 'input_type' => null, 'message' => 'Mutation responded without required arguments'];
        }

        if ($this->graphQlErrorIndicatesMissingField($message, $mutationName)) {
            return ['exists' => false, 'arg_name' => null, 'input_type' => null, 'message' => $message];
        }

        $argName = null;
        $inputType = null;
        if (preg_match('/argument "(data|input)" of type "([^"]+)!"/', $message, $matches) === 1) {
            $argName = $matches[1];
            $inputType = $matches[2];
        }

        return [
            'exists' => true,
            'arg_name' => $argName,
            'input_type' => $inputType,
            'message' => $message !== '' ? $message : 'Mutation exists',
        ];
    }

    /**
     * Brute-force hide-orders sync config when introspection is unavailable.
     *
     * @return array{mutation: string, input_type: string, id_field: string, hide_field: string, arg_name: string}|null
     */
    public function bruteForceDiscoverHideOrdersSyncConfig(?string $trialCustomerAccountId = null): ?array
    {
        $customerId = trim((string) $trialCustomerAccountId);
        if ($customerId === '') {
            $customerId = '0';
        }

        foreach ($this->hideOrdersMutationCandidates() as $mutationName) {
            $signature = $this->probeMutationSignature($mutationName);
            if (! $signature['exists']) {
                continue;
            }

            $argName = $signature['arg_name'] ?? 'data';
            $inputTypes = [];
            if (isset($signature['input_type']) && is_string($signature['input_type']) && $signature['input_type'] !== '') {
                $inputTypes[] = $signature['input_type'];
            }
            foreach ($this->hideOrdersInputTypeCandidates($mutationName) as $candidateInputType) {
                if (! in_array($candidateInputType, $inputTypes, true)) {
                    $inputTypes[] = $candidateInputType;
                }
            }

            foreach ($inputTypes as $inputType) {
                foreach ($this->hideOrdersFieldCandidates() as $hideField) {
                    foreach (['customer_account_id', 'id', 'account_id'] as $idField) {
                        $config = [
                            'mutation' => $mutationName,
                            'input_type' => $inputType,
                            'id_field' => $idField,
                            'hide_field' => $hideField,
                            'arg_name' => $argName,
                        ];
                        if ($this->trialHideOrdersMutation($config, $customerId, true)) {
                            return $config;
                        }
                        if ($this->trialHideOrdersMutation($config, $customerId, false)) {
                            return $config;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array{mutation: string, input_type: string, id_field: string, hide_field: string, arg_name: string}  $config
     */
    public function trialHideOrdersMutation(array $config, string $customerAccountId, bool $hide): bool
    {
        $data = [
            $config['id_field'] => $customerAccountId,
            $config['hide_field'] => $hide,
        ];
        $argName = $config['arg_name'];
        $graphql = <<<GQL
mutation ShipHeroProbeHideOrders(\${$argName}: {$config['input_type']}!) {
  {$config['mutation']}({$argName}: \${$argName}) {
    request_id
  }
}
GQL;

        $raw = $this->client->queryRawDiagnostic($graphql, [$argName => $data]);
        $body = json_decode($raw['body'], true);
        if (! is_array($body)) {
            return false;
        }

        if (is_array(data_get($body, 'data.'.$config['mutation']))) {
            return true;
        }

        $message = strtolower($this->firstGraphQlErrorMessage($body));
        if ($message === '') {
            return false;
        }

        if (
            str_contains($message, 'cannot query field')
            || str_contains($message, 'unknown argument')
            || str_contains($message, 'is not defined on type')
            || str_contains($message, 'field "'.strtolower($config['hide_field']).'"')
            || str_contains($message, 'field "'.strtolower($config['id_field']).'"')
        ) {
            return false;
        }

        // Wrong customer id / auth issues still mean the mutation + fields exist.
        return str_contains($message, 'customer')
            || str_contains($message, 'account')
            || str_contains($message, 'not found')
            || str_contains($message, 'invalid')
            || str_contains($message, 'permission')
            || str_contains($message, 'access');
    }

    /**
     * @return list<string>
     */
    public function hideOrdersMutationCandidates(): array
    {
        return [
            'customer_account_update',
            'three_pl_customer_update',
            'customer_account_settings_update',
            'three_pl_customer_settings_update',
            'customer_warehouse_relationship_update',
            'warehouse_relationship_update',
            'three_pl_warehouse_relationship_update',
            'customer_account_warehouse_relationship_update',
            'update_customer_account',
            'child_account_update',
            'three_pl_child_account_update',
            'account_customer_update',
            'customer_relationship_update',
            'three_pl_customer_relationship_update',
        ];
    }

    /**
     * @return list<string>
     */
    public function hideOrdersFieldCandidates(): array
    {
        return [
            'hide_orders_from_app',
            'hide_customers_orders_from_app',
            'hide_customer_orders_from_app',
            'orders_hidden_from_app',
            'hide_from_app',
            'pause_shipping',
        ];
    }

    /**
     * @return list<string>
     */
    protected function hideOrdersInputTypeCandidates(string $mutationName): array
    {
        $snake = strtolower($mutationName);
        $candidates = [
            'UpdateCustomerAccountInput',
            'CustomerAccountUpdateInput',
            'UpdateThreePlCustomerInput',
            'ThreePlCustomerUpdateInput',
            'UpdateCustomerWarehouseRelationshipInput',
            'UpdateWarehouseRelationshipInput',
            'ThreePlWarehouseRelationshipUpdateInput',
        ];

        if (str_contains($snake, 'warehouse') || str_contains($snake, 'relationship')) {
            return [
                'UpdateWarehouseRelationshipInput',
                'UpdateCustomerWarehouseRelationshipInput',
                'ThreePlWarehouseRelationshipUpdateInput',
                'UpdateCustomerAccountWarehouseRelationshipInput',
                ...$candidates,
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function firstGraphQlErrorMessage(array $json): string
    {
        $errors = $json['errors'] ?? null;
        if (! is_array($errors) || ! isset($errors[0])) {
            return '';
        }
        $first = $errors[0];

        return is_array($first) ? trim((string) ($first['message'] ?? '')) : trim((string) $first);
    }

    protected function graphQlErrorIndicatesMissingField(string $message, string $fieldName): bool
    {
        $message = strtolower($message);
        $fieldName = strtolower($fieldName);

        return str_contains($message, 'cannot query field "'.$fieldName.'"')
            || str_contains($message, 'unknown field "'.$fieldName.'"')
            || str_contains($message, 'field "'.$fieldName.'" must not have a selection');
    }

    /**
     * Probe schema for hide-orders update capability (for artisan / docs).
     *
     * @return array{
     *   update_mutations: list<string>,
     *   customer_account_fields: list<array{name: string, type_kind: string|null, type_name: string|null}>,
     *   discovered_config: array<string, string>|null,
     *   hide_field_candidates: list<string>
     * }
     */
    public function probeHideOrdersCapability(): array
    {
        $updateMutations = $this->findCustomerAccountUpdateMutations();
        $customerAccountFields = $this->introspectObjectTypeFields('CustomerAccount');
        $hideFieldCandidates = $this->collectHideFieldCandidates($customerAccountFields);

        foreach ($updateMutations as $mutationName) {
            $inputType = $this->resolveMutationDataInputTypeName($mutationName);
            if ($inputType === null) {
                continue;
            }
            foreach ($this->collectHideFieldCandidates($this->introspectInputTypeFields($inputType)) as $field) {
                $hideFieldCandidates[] = $field;
            }
        }

        $hideFieldCandidates = array_values(array_unique($hideFieldCandidates));
        $discovered = $this->discoverHideOrdersSyncConfigFromSchema();
        if ($discovered === null) {
            $discovered = $this->bruteForceDiscoverHideOrdersSyncConfig();
        }

        return [
            'update_mutations' => $updateMutations,
            'customer_account_fields' => $customerAccountFields,
            'discovered_config' => $discovered,
            'hide_field_candidates' => $hideFieldCandidates,
        ];
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

    /**
     * @return array{mutation: string, input_type: string, id_field: string, hide_field: string}|null
     */
    protected function resolveHideOrdersSyncConfig(): ?array
    {
        $mutation = trim((string) config('services.shiphero.customer_account_update_mutation', ''));
        $hideField = trim((string) config('services.shiphero.customer_account_hide_orders_field', ''));
        $idField = trim((string) config('services.shiphero.customer_account_id_field', 'customer_account_id'));

        if ($mutation !== '' && $hideField !== '') {
            $inputType = trim((string) config('services.shiphero.customer_account_update_input_type', ''));
            if ($inputType === '') {
                $inputType = $this->resolveMutationDataInputTypeName($mutation) ?? 'UpdateCustomerAccountInput';
            }

            return [
                'mutation' => $mutation,
                'input_type' => $inputType,
                'id_field' => $idField !== '' ? $idField : 'customer_account_id',
                'hide_field' => $hideField,
                'arg_name' => $this->resolveMutationInputArgName($mutation) ?? 'data',
            ];
        }

        $cached = Cache::get(self::CACHE_HIDE_ORDERS_CONFIG);
        if ($cached === '__missing__') {
            return null;
        }
        if (is_array($cached) && isset($cached['mutation'])) {
            return $cached;
        }

        $discovered = $this->discoverHideOrdersSyncConfigFromSchema();
        if ($discovered !== null) {
            Cache::put(self::CACHE_HIDE_ORDERS_CONFIG, $discovered, 86400);

            return $discovered;
        }

        Cache::put(self::CACHE_HIDE_ORDERS_CONFIG, '__missing__', 300);

        return null;
    }

    /**
     * @return array{mutation: string, input_type: string, id_field: string, hide_field: string}|null
     */
    protected function discoverHideOrdersSyncConfigFromSchema(): ?array
    {
        foreach ($this->findCustomerAccountUpdateMutations() as $mutationName) {
            $inputType = $this->resolveMutationDataInputTypeName($mutationName);
            if ($inputType === null) {
                continue;
            }

            $fields = $this->introspectInputTypeFields($inputType);
            $hideField = $this->pickHideOrdersFieldName($fields);
            if ($hideField === null) {
                continue;
            }

            $idField = $this->pickCustomerAccountIdFieldName($fields);
            if ($idField === null) {
                continue;
            }

            return [
                'mutation' => $mutationName,
                'input_type' => $inputType,
                'id_field' => $idField,
                'hide_field' => $hideField,
                'arg_name' => $this->resolveMutationInputArgName($mutationName) ?? 'data',
            ];
        }

        return null;
    }

    /**
     * @param  array{mutation: string, input_type: string, id_field: string, hide_field: string}  $config
     * @param  array<string, mixed>  $data
     */
    protected function executeHideOrdersMutation(array $config, array $data): void
    {
        $mutation = $config['mutation'];
        $inputType = $config['input_type'];
        $preferredArg = isset($config['arg_name']) && is_string($config['arg_name'])
            ? $config['arg_name']
            : 'data';

        $argOrder = array_values(array_unique([$preferredArg, 'data', 'input']));
        $lastMessage = 'ShipHero customer account update failed.';

        foreach ($argOrder as $argName) {
            $graphql = <<<GQL
mutation ShipHeroCustomerAccountHideOrders(\${$argName}: {$inputType}!) {
  {$mutation}({$argName}: \${$argName}) {
    request_id
  }
}
GQL;

            try {
                $json = $this->client->query($graphql, [$argName => $data]);
            } catch (\Throwable $e) {
                $lastMessage = $e->getMessage();
                continue;
            }

            $payload = data_get($json, 'data.'.$mutation);
            if (is_array($payload)) {
                return;
            }

            $errors = data_get($json, 'errors');
            $lastMessage = is_array($errors) && isset($errors[0]['message'])
                ? (string) $errors[0]['message']
                : 'ShipHero customer account update failed.';
        }

        throw new \RuntimeException($lastMessage);
    }

    protected function resolveMutationInputArgName(string $mutationName): ?string
    {
        foreach ($this->listMutationFieldsWithArgs() as $field) {
            if ($field['name'] !== $mutationName) {
                continue;
            }
            foreach ($field['args'] as $arg) {
                $argName = isset($arg['name']) && is_string($arg['name']) ? $arg['name'] : '';
                if ($argName !== 'data' && $argName !== 'input') {
                    continue;
                }
                $type = is_array($arg['type'] ?? null) ? $arg['type'] : [];
                $name = $this->unwrapGraphQlTypeName($type);
                if ($name !== null && $name !== '') {
                    return $argName;
                }
            }
        }

        return null;
    }

    protected function resolveMutationDataInputTypeName(string $mutationName): ?string
    {
        foreach ($this->listMutationFieldsWithArgs() as $field) {
            if ($field['name'] !== $mutationName) {
                continue;
            }
            foreach ($field['args'] as $arg) {
                $argName = isset($arg['name']) && is_string($arg['name']) ? $arg['name'] : '';
                if ($argName !== 'data' && $argName !== 'input') {
                    continue;
                }
                $type = is_array($arg['type'] ?? null) ? $arg['type'] : [];
                $name = $this->unwrapGraphQlTypeName($type);
                if ($name !== null && $name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, type_kind: string|null, type_name: string|null}>  $fields
     * @return list<string>
     */
    protected function collectHideFieldCandidates(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $name = strtolower($field['name']);
            if (strpos($name, 'hide') !== false && (strpos($name, 'app') !== false || strpos($name, 'order') !== false)) {
                $out[] = $field['name'];
                continue;
            }
            if (strpos($name, 'orders_from_app') !== false || strpos($name, 'hide_from_app') !== false) {
                $out[] = $field['name'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{name: string, type_kind: string|null, type_name: string|null}>  $fields
     */
    protected function pickHideOrdersFieldName(array $fields): ?string
    {
        $configured = trim((string) config('services.shiphero.customer_account_hide_orders_field', ''));
        if ($configured !== '') {
            foreach ($fields as $field) {
                if ($field['name'] === $configured) {
                    return $configured;
                }
            }
        }

        $best = null;
        $bestScore = -1;
        foreach ($fields as $field) {
            if (strtolower((string) ($field['type_name'] ?? '')) !== 'boolean') {
                continue;
            }
            $name = strtolower($field['name']);
            if (strpos($name, 'hide') === false) {
                continue;
            }
            $score = 0;
            if (strpos($name, 'order') !== false) {
                $score += 3;
            }
            if (strpos($name, 'app') !== false) {
                $score += 2;
            }
            if (strpos($name, 'customer') !== false) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $field['name'];
            }
        }

        return $best;
    }

    /**
     * @param  list<array{name: string, type_kind: string|null, type_name: string|null}>  $fields
     */
    protected function pickCustomerAccountIdFieldName(array $fields): ?string
    {
        $preferred = ['customer_account_id', 'id', 'account_id'];
        foreach ($preferred as $want) {
            foreach ($fields as $field) {
                if ($field['name'] === $want) {
                    return $want;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    protected function unwrapGraphQlTypeName(array $type): ?string
    {
        if (isset($type['name']) && is_string($type['name']) && $type['name'] !== '') {
            return $type['name'];
        }
        if (isset($type['ofType']) && is_array($type['ofType'])) {
            return $this->unwrapGraphQlTypeName($type['ofType']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    protected function unwrapGraphQlTypeKind(array $type): ?string
    {
        $kind = isset($type['kind']) && is_string($type['kind']) ? $type['kind'] : null;
        if ($kind === 'NON_NULL' || $kind === 'LIST') {
            if (isset($type['ofType']) && is_array($type['ofType'])) {
                return $this->unwrapGraphQlTypeKind($type['ofType']);
            }
        }

        return $kind;
    }
}
