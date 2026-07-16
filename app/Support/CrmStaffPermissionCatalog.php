<?php

namespace App\Support;

/**
 * Staff permissions matrix: one row per CRM nav subpage (ASN, Put Away, etc.).
 * Legacy module keys (receiving.view) still work via aliases until fully migrated.
 */
class CrmStaffPermissionCatalog
{
    /**
     * @return list<array{key: string, label: string, module: string}>
     */
    public static function definitions(): array
    {
        $rows = [];

        $add = static function (array &$rows, string $key, string $label, string $module): void {
            $rows[] = ['key' => $key, 'label' => $label, 'module' => $module];
        };

        // Dashboard / tickets (no sub-nav)
        $add($rows, 'dashboard.view', 'View dashboard', 'dashboard');
        foreach (['view', 'create', 'update', 'delete'] as $a) {
            $add($rows, 'tickets.'.$a, ucfirst($a).' tickets', 'tickets');
        }
        $add($rows, 'tickets.comment', 'Comment on tickets', 'tickets');

        // Clients (already split)
        foreach (['view', 'create', 'update', 'delete'] as $a) {
            $add($rows, 'clients.'.$a, ucfirst($a).' client accounts', 'clients');
            $add($rows, 'client_users.'.$a, ucfirst($a).' client portal users', 'client_users');
            $add($rows, 'stores.'.$a, ucfirst($a).' client stores', 'stores');
            $add($rows, 'projects.'.$a, ucfirst($a).' projects', 'projects');
        }

        // Orders subpages — full CRUD (queue mutations, wholesale, create order)
        foreach ([
            'orders_search' => 'orders search',
            'orders_fulfillment' => 'orders fulfillment',
            'orders_awaiting' => 'orders ready to ship',
            'orders_on_hold' => 'orders on-hold',
            'orders_backorder' => 'orders backorder',
            'orders_shipped' => 'orders shipped',
            'orders_wholesale' => 'orders wholesale',
            'orders_create' => 'create order',
        ] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Receiving — ASN create/receive/delete; Put Away stock edits
        foreach (['receiving_asn' => 'ASN', 'receiving_put_away' => 'put away'] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Returns — process create/edit/delete; lists + bins
        foreach ([
            'returns_process' => 'process returns',
            'returns_orders' => 'returned orders',
            'returns_items' => 'returned items',
            'returns_bins' => 'return bins',
        ] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Inventory
        foreach ([
            'inventory_products' => 'inventory products',
            'inventory_out_of_stock' => 'out of stock',
            'inventory_restock' => 'restock',
            'inventory_on_demand' => 'on-demand',
        ] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Billing
        foreach ([
            'billing_summary' => 'billing summary',
            'billing_invoices' => 'invoices',
            'billing_custom_bills' => 'custom bills',
            'billing_asn_bills' => 'ASN bills',
            'billing_return_bills' => 'returns bills',
        ] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Resources
        foreach ([
            'resources_tutorials' => 'tutorials',
            'resources_photos' => 'photos',
            'resources_calendar' => 'calendar',
            'resources_events' => 'events',
        ] as $mod => $label) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$label, $mod);
            }
        }

        // Admin-only (seeded, not shown in staff matrix)
        foreach (['users', 'webmaster'] as $mod) {
            foreach (['view', 'create', 'update', 'delete'] as $a) {
                $add($rows, $mod.'.'.$a, ucfirst($a).' '.$mod, $mod);
            }
        }
        $add($rows, 'settings.view', 'View settings', 'settings');
        $add($rows, 'settings.update', 'Update settings', 'settings');

        // Legacy coarse keys (compat / migration source; hidden from staff matrix UI)
        foreach (['view', 'create', 'update', 'delete'] as $a) {
            $add($rows, 'billing.'.$a, ucfirst($a).' billing (legacy)', 'billing');
            $add($rows, 'resources.'.$a, ucfirst($a).' resources (legacy)', 'resources');
        }
        $add($rows, 'inventory.view', 'View inventory (legacy)', 'inventory');
        $add($rows, 'inventory.create', 'Create inventory (legacy)', 'inventory');
        $add($rows, 'inventory.update', 'Update inventory (legacy)', 'inventory');
        $add($rows, 'inventory.delete', 'Delete inventory (legacy)', 'inventory');
        $add($rows, 'receiving.view', 'View receiving (legacy)', 'receiving');
        $add($rows, 'receiving.create', 'Create receiving (legacy)', 'receiving');
        $add($rows, 'receiving.update', 'Update receiving (legacy)', 'receiving');
        $add($rows, 'receiving.delete', 'Delete receiving (legacy)', 'receiving');
        $add($rows, 'returns.view', 'View returns (legacy)', 'returns');
        $add($rows, 'returns.create', 'Create returns (legacy)', 'returns');
        $add($rows, 'returns.update', 'Update returns (legacy)', 'returns');
        $add($rows, 'returns.delete', 'Delete returns (legacy)', 'returns');
        $add($rows, 'orders.view', 'View orders (legacy)', 'orders');
        $add($rows, 'orders.create', 'Create orders (legacy)', 'orders');
        $add($rows, 'orders.update', 'Update orders (legacy)', 'orders');
        $add($rows, 'orders.delete', 'Delete orders (legacy)', 'orders');

        return $rows;
    }

    /**
     * Ops modules where legacy `.update` historically covered create/delete mutations.
     *
     * @return list<string>
     */
    public static function opsModulesWhereUpdateImpliesMutations(): array
    {
        return ['orders', 'receiving', 'returns', 'inventory'];
    }

    /**
     * Legacy module prefix → child prefixes that replace it in the matrix.
     *
     * @return array<string, list<string>>
     */
    public static function legacyToChildren(): array
    {
        return [
            'orders' => [
                'orders_search',
                'orders_fulfillment',
                'orders_awaiting',
                'orders_on_hold',
                'orders_backorder',
                'orders_shipped',
                'orders_wholesale',
                'orders_create',
            ],
            'receiving' => [
                'receiving_asn',
                'receiving_put_away',
            ],
            'returns' => [
                'returns_process',
                'returns_orders',
                'returns_items',
                'returns_bins',
            ],
            'inventory' => [
                'inventory_products',
                'inventory_out_of_stock',
                'inventory_restock',
                'inventory_on_demand',
            ],
            'billing' => [
                'billing_summary',
                'billing_invoices',
                'billing_custom_bills',
                'billing_asn_bills',
                'billing_return_bills',
            ],
            'resources' => [
                'resources_tutorials',
                'resources_photos',
                'resources_calendar',
                'resources_events',
            ],
        ];
    }

    /**
     * Module prefixes hidden from the staff permissions matrix (replaced by children).
     *
     * @return list<string>
     */
    public static function legacyMatrixModules(): array
    {
        return array_keys(self::legacyToChildren());
    }

    /**
     * Keys shown / assignable in the staff permissions UI.
     *
     * @return list<string>
     */
    public static function matrixEditableKeys(): array
    {
        $legacyModules = array_flip(self::legacyMatrixModules());
        $adminOnly = array_flip(['users', 'webmaster', 'settings']);
        $out = [];

        foreach (self::definitions() as $def) {
            $key = $def['key'];
            if (! preg_match('/^([a-z0-9_]+)\.(view|create|update|delete)$/i', $key, $m)) {
                continue;
            }
            $module = strtolower($m[1]);
            if (isset($legacyModules[$module]) || isset($adminOnly[$module])) {
                continue;
            }
            $out[] = $key;
        }

        return array_values(array_unique($out));
    }

    /**
     * Expand a legacy key (e.g. receiving.view) into child keys.
     *
     * @return list<string>
     */
    public static function expandLegacyKey(string $key): array
    {
        $key = trim($key);
        if ($key === '' || ! preg_match('/^([a-z0-9_]+)\.(view|create|update|delete)$/i', $key, $m)) {
            return [];
        }
        $module = strtolower($m[1]);
        $action = strtolower($m[2]);
        $children = self::legacyToChildren()[$module] ?? null;
        if ($children === null) {
            return [];
        }

        $out = [];
        foreach ($children as $child) {
            $out[] = $child.'.'.$action;
        }

        // Legacy update covered create/delete mutations on ops pages.
        if (
            $action === 'update'
            && in_array($module, self::opsModulesWhereUpdateImpliesMutations(), true)
        ) {
            foreach ($children as $child) {
                $out[] = $child.'.create';
                $out[] = $child.'.delete';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Keys that satisfy a permission check for $key (exact + legacy aliases).
     *
     * @return list<string>
     */
    public static function keysSatisfying(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return [];
        }

        $out = [$key];

        // Checking legacy parent → also accept any child with same action (+ create/delete for update).
        foreach (self::expandLegacyKey($key) as $child) {
            $out[] = $child;
        }

        // Checking a child → also accept legacy parent with same action.
        if (preg_match('/^([a-z0-9_]+)\.(view|create|update|delete)$/i', $key, $m)) {
            $module = strtolower($m[1]);
            $action = strtolower($m[2]);
            foreach (self::legacyToChildren() as $legacy => $children) {
                if (! in_array($module, $children, true)) {
                    continue;
                }
                $out[] = $legacy.'.'.$action;
                // Child create/delete also satisfied by legacy update on ops modules.
                if (
                    in_array($action, ['create', 'delete'], true)
                    && in_array($legacy, self::opsModulesWhereUpdateImpliesMutations(), true)
                ) {
                    $out[] = $legacy.'.update';
                }
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * True if any of $grantedKeys satisfies $neededKey (legacy-aware).
     *
     * @param  list<string>  $grantedKeys
     */
    public static function grants(array $grantedKeys, string $neededKey): bool
    {
        $granted = array_flip($grantedKeys);
        foreach (self::keysSatisfying($neededKey) as $candidate) {
            if (isset($granted[$candidate])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function allDefinitionKeys(): array
    {
        $out = [];
        foreach (self::definitions() as $def) {
            $out[] = $def['key'];
        }

        return array_values(array_unique($out));
    }
}
