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

If auto-discovery succeeds, the command prints `.env` keys to copy. **Do not guess** mutation names — ShipHero's Public API does not include `customer_account_update`. If the probe finds nothing, leave `SHIPHERO_CUSTOMER_ACCOUNT_*` unset (or set `SHIPHERO_CUSTOMER_ACCOUNT_HIDE_ORDERS_SYNC=false`).

Sync is skipped when `shiphero_customer_account_id` is empty. CRM status always saves in CRM even when ShipHero sync is skipped. Hide-orders sync does **not** change a general "account status" in ShipHero — it only targets the **Hide Customer's Orders From App** checkbox on the 3PL customer page, and that checkbox may only be editable in the ShipHero UI ([help article](https://software-help.shiphero.com/hc/en-us/articles/4419345401485)).

**If `php artisan shiphero:probe-customer-mutations` finds nothing:** ShipHero often disables GraphQL schema introspection on production tokens. The probe now tries known mutation names directly. If still nothing is found, the [Hide Customer's Orders From App](https://software-help.shiphero.com/hc/en-us/articles/4419345401485) setting may only be available in the ShipHero UI (3PL Customers), not the Public API. Toggle it manually there, or ask ShipHero support whether your account has an API mutation for it.

Retry with a real customer id:

```bash
php artisan shiphero:probe-customer-mutations --test-customer-id=92441
```

## In-house Slack on status change

When CRM account status changes (single edit or bulk), the app posts to that account's **In-house Slack** field (`in_house_slack`) — channel slug, `#name`, or Slack archive URL. Uses the same Slack delivery as invoice review (`SLACK_WEBHOOK_URL` or bot token). Failures are logged only; the CRM save still succeeds.

## Environment

- `SHIPHERO_REFRESH_TOKEN` — required for probe and all ShipHero calls
- `REGISTRATION_NOTIFY_EMAIL` — optional; defaults to `ADMIN_EMAIL`
