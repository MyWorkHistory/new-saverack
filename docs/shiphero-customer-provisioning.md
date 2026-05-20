# ShipHero customer account provisioning

## Summary

Self-serve 3PL signup creates a CRM `client_accounts` row and portal user in **pending** status. ShipHero inventory/orders APIs require `client_accounts.shiphero_customer_account_id` (GraphQL `customer_account_id`).

## API research

ShipHero’s public docs and community threads do not document a stable `customer_account_create` mutation for 3PL self-serve registration. This app probes the live schema via:

```bash
php artisan shiphero:probe-customer-mutations
```

If no suitable mutation is found, provisioning falls back to **staff manual** setup in CRM (Payment section → ShipHero customer account ID).

## Staff workflow

1. Receive **New 3PL signup** email (`REGISTRATION_NOTIFY_EMAIL` / `ADMIN_EMAIL`).
2. Open the client account in CRM.
3. Create or locate the customer in ShipHero UI; copy the GraphQL `customer_account_id`.
4. Paste into **ShipHero customer account ID** on the account.
5. Set account status and primary portal user to **active**.

Activation to `active` requires a non-empty ShipHero customer account ID (see `ClientAccountUpdateRequest`).

## Environment

- `SHIPHERO_REFRESH_TOKEN` — required for probe and all ShipHero calls
- `REGISTRATION_NOTIFY_EMAIL` — optional; defaults to `ADMIN_EMAIL`
