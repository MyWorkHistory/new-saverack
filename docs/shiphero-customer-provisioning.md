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

## Hide orders from app (CRM account status sync)

When CRM account status changes, the app tries to sync ShipHero **Hide Customer's Orders From App**:

| CRM status | ShipHero checkbox |
|---|---|
| `active` | Unchecked (orders visible) |
| `pending`, `paused`, `inactive` | Checked (orders hidden) |

Probe the live schema:

```bash
php artisan shiphero:probe-customer-mutations
```

If auto-discovery succeeds, the command prints `.env` keys to copy. Otherwise set manually after confirming field names in ShipHero's GraphQL schema:

- `SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_MUTATION`
- `SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_INPUT_TYPE` (optional if probe can infer from mutation)
- `SHIPHERO_CUSTOMER_ACCOUNT_HIDE_ORDERS_FIELD`
- `SHIPHERO_CUSTOMER_ACCOUNT_ID_FIELD` (default: `customer_account_id`)

Sync is skipped when `shiphero_customer_account_id` is empty. CRM status always saves even if ShipHero sync fails. When a ShipHero customer ID is set but the update API is not configured, the CRM response includes `shiphero_sync.ok: false` so staff see a warning.

## In-house Slack on status change

When CRM account status changes (single edit or bulk), the app posts to that account's **In-house Slack** field (`in_house_slack`) — channel slug, `#name`, or Slack archive URL. Uses the same Slack delivery as invoice review (`SLACK_WEBHOOK_URL` or bot token). Failures are logged only; the CRM save still succeeds.

## Environment

- `SHIPHERO_REFRESH_TOKEN` — required for probe and all ShipHero calls
- `REGISTRATION_NOTIFY_EMAIL` — optional; defaults to `ADMIN_EMAIL`
