## SaveRack CRM (Phase 1)

Laravel 11 API (Sanctum) + Vue 3 TailAdmin SPA served from `public/index.html` (see `vite.spa.config.js`).

### Setup

1. Install PHP 8.2+ and [Composer](https://getcomposer.org/).
2. `cp .env.example .env` and set `APP_KEY`, `DB_*`, `ADMIN_*`, mail for password reset.
3. `composer install`
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. API: `php artisan serve` (http://127.0.0.1:8000). Prefix: `/api`.

### Frontend (TailAdmin)

Source: `D:\app.saverack.com\vue-tailwind-admin-dashboard-main\vue-tailwind-admin-dashboard-main` (or your clone).

- Dev: `npm install` then `npm run dev` (Vite proxies `/api` to `http://127.0.0.1:8000`).
- Production build: `npm run build` — Laravel assets to `public/build/`, CRM SPA to `public/index.html` + `public/assets/*`. Subpath: set `VITE_APP_BASE=/your-prefix/` for both `build:spa` / `build` SPA step.

Open the app via Laravel: `http://127.0.0.1:8000/` (SPA) and sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

### Docs

- [Legacy field mapping](docs/field-mapping-legacy-to-new.md)
- [ETL next steps](docs/etl-next-steps.md)
- [Legacy review notes](docs/legacy-review-save_net.md)

### Inventory catalog sync (production)

- Run migrations after deploy: `php artisan migrate --force`
- Run a persistent queue worker: `php artisan queue:work database-long --timeout=3700 --tries=1`
- If sync is stuck `running` and list returns 409: `php artisan inventory:reset-catalog-sync` then `php artisan inventory:reset-catalog-sync 271`
- Reload PHP-FPM / clear OPcache after backend deploy; deploy `public/assets/*` after `npm run build:spa`

**Smoke test after deploy**

| Request | Expected |
|---------|----------|
| `GET /api/inventory-beta/catalog-sync?client_account_id=271` | 200 in under 1s; `inventory_beta.catalog_sync.completed` in `laravel.log` |
| `GET /api/inventory-beta/list?client_account_id=271&first=50` | 200 in under 2s; `inventory_beta.list.start` + `inventory_beta.list.completed` in log |
| `GET ...&refresh=1` | 202; worker logs `inventory.catalog_sync.page` |
| After sync idle | List populates from local index without ShipHero GraphQL on reads |

If `inventory_beta.list.start` never appears in the log, the request is not reaching Laravel (undeployed code, OPcache, or PHP-FPM pool exhaustion).

### Post DB recovery

After recreating or wiping the database, local ShipHero index tables are empty. The CRM reads from those tables for inventory, order queue tabs, and admin Home metrics — not live ShipHero on every page load. Run this sequence on production:

1. **Prerequisites**
   - `SHIPHERO_REFRESH_TOKEN` set in `.env`
   - `crm:import-shiphero-customer-ids` (or manual `shiphero_customer_account_id` on accounts)
   - `QUEUE_CONNECTION=database` (not `sync` only)

2. **Start a persistent queue worker** (critical — without it, refresh jobs never run):

   ```bash
   php artisan queue:work database-long --timeout=3700 --tries=1 --sleep=3
   ```

   If `database-long` is not configured, use `database` instead.

3. **Warm snapshots** (inline, no worker required for these two):

   ```bash
   php artisan orders:sync-queue-index --sync
   php artisan orders:refresh-home-dashboard --sync
   ```

   **Important:** Home and Fulfillment counts come from `order_dashboard_sections` and can be filled from live ShipHero during dashboard refresh. Order **list** pages (Ready to Ship, Shipped, Backorder) read only `shiphero_order_queue_index`. If counts show but lists are empty, run `orders:sync-queue-index --sync` — `orders:refresh-home-dashboard --sync` alone is not enough.

   Or use the combined helper:

   ```bash
   php artisan crm:warm-shiphero-data
   ```

4. **Inventory** — per account: Products → **Refresh Inventory** (or **Sync Products** for a full rebuild). Inventory has no bulk-all-accounts artisan command unless you run `crm:warm-shiphero-data` without `--skip-inventory` (queues catalog jobs; worker required).

5. **Diagnose** after any incident:

   ```bash
   php artisan crm:diagnose-shiphero
   ```

6. **Reset stuck sync** if a catalog sync is stuck `running`:

   ```bash
   php artisan inventory:reset-catalog-sync
   php artisan inventory:reset-catalog-sync {client_account_id}
   ```

7. **Optional** — register webhooks for near-real-time updates: `php artisan shiphero:register-webhooks`

### Live ShipHero sync (scheduled + webhooks + UI)

Near-real-time updates use webhooks when available, lightweight scheduled jobs as fallback, and revision polling in the CRM UI (~30s).

**Production prerequisites**

1. Laravel scheduler cron (single entry):

   ```bash
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```

2. Persistent queue worker:

   ```bash
   php artisan queue:work database-long --timeout=3700 --tries=1 --sleep=3
   ```

3. `.env`: `SHIPHERO_REFRESH_TOKEN`, `SHIPHERO_WEBHOOK_URL`, `SHIPHERO_WEBHOOK_SECRET`, `QUEUE_CONNECTION=database`

4. Register webhooks after deploy: `php artisan shiphero:register-webhooks`

**Scheduled cadence (America/New_York)**

| Window | Cadence | Commands |
|--------|---------|----------|
| 7am–5pm | Every 15 min | `orders:sync-recent-updates`, `orders:refresh-home-dashboard --from-index`, `inventory:sync-catalog-incremental` |
| Off-hours | Every 30 min | Same three commands |
| 2:00am nightly | Once | `orders:sync-queue-index --sync` (full index safety net) |

Lightweight scheduled sync reads/writes the local index — it does **not** run full `orders:sync-queue-index` every 15 minutes.

**Webhook types**

| Category | Types |
|----------|-------|
| Orders | Shipment Update, Order Canceled, Order Allocated, Order Deallocated, Order Packed Out |
| Inventory | Inventory Update, Inventory Change |

**UI revision polling**

| Page | Endpoint | On bump |
|------|----------|---------|
| Admin Home / Fulfillment | `GET /api/home-dashboard/revision` | Reload dashboard |
| Portal home | `GET /api/orders/queue-counts/revision` | Reload queue counts |
| Orders list (queue tabs) | `GET /api/orders/queue-counts/revision` | Reload list from index |
| Products list | `GET /api/inventory-beta/revision` | Reload list from index |

**Diagnose**

```bash
php artisan crm:diagnose-shiphero
```

Reports `QUEUE_CONNECTION`, pending/failed jobs, last scheduled sync run times, pending webhook events, sync status, index counts, and sample inventory revision counters.

**cPanel: queue worker must stay running**

SSH `php artisan queue:work` stops when you disconnect. Use a **second cron** (every minute) so jobs drain without an open SSH session:

```bash
* * * * * cd /home/saverack/app.saverack.com && /opt/cpanel/ea-php74/root/usr/bin/php artisan queue:work database --stop-when-empty --max-time=55 >> storage/logs/queue-worker.log 2>&1
* * * * * cd /home/saverack/app.saverack.com && /opt/cpanel/ea-php74/root/usr/bin/php artisan queue:work database-long --stop-when-empty --max-time=55 >> storage/logs/queue-worker-long.log 2>&1
```

Adjust paths to your app root and PHP binary. Check `storage/logs/queue-worker*.log` if jobs pile up.

**If lists are empty but Home shows counts**

Lightweight cron does not full-rebuild indexes. Run once after deploy or DB wipe:

```bash
php artisan crm:warm-shiphero-data
```

Or manually: `php artisan orders:sync-queue-index --sync` then `php artisan orders:refresh-home-dashboard --sync`.

**Verify deploy**

```bash
php artisan schedule:list | grep inventory:sync-catalog-incremental
```

If that line is missing, pull latest code (`git pull`) and reload PHP-FPM / clear OPcache.

**Smoke test after deploy**

| Step | Expected |
|------|----------|
| `php artisan schedule:list` | 15-min jobs 7–17 ET; 30-min off-hours; nightly full index at 2am |
| `HEAD /api/shiphero/webhook` | 200 |
| Ship order in ShipHero | `shiphero.webhook.processed` in log; Home/Fulfillment counts update within ~30s |
| Change inventory qty in ShipHero | `shiphero.inventory_webhook.processed` in log; Products list updates within ~30s |
| `GET /api/inventory-beta/revision?client_account_id=` | `{ "revision": N }` increments after webhook/sync |

### ShipHero order webhooks (near-real-time dashboard counts)

1. Set in production `.env`:
   - `SHIPHERO_WEBHOOK_URL=https://your-domain/api/shiphero/webhook`
   - `SHIPHERO_WEBHOOK_SECRET=` (from ShipHero `webhook_create` response — shown once)
2. `php artisan migrate --force` (creates `shiphero_webhook_events`)
3. Ensure queue worker processes `ProcessShipHeroOrderWebhookJob` and `ProcessShipHeroInventoryWebhookJob` (same worker as other jobs)
4. Register webhooks: `php artisan shiphero:register-webhooks`
5. Scheduled fallback: see **Live ShipHero sync** above (`orders:sync-recent-updates`, dashboard refresh from index, inventory incremental sync)

**Smoke test after deploy**

| Step | Expected |
|------|----------|
| `HEAD /api/shiphero/webhook` | 200 (ShipHero endpoint validation) |
| Ship one order in ShipHero | Row in `shiphero_webhook_events`; `shiphero.webhook.processed` in log |
| Portal home within ~30s | `GET /api/orders/queue-counts/revision` revision increments; snapshot counts update |
| `GET /api/orders/queue-counts/snapshot?client_account_id=` | Single fast response from `shiphero_order_queue_index` |

Subscribed webhook types: Shipment Update, Order Canceled, Order Allocated, Order Deallocated, Order Packed Out, Inventory Update, Inventory Change.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
