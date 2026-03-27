# ETL next steps (post-MVP)

Planned after Phase 1 API + UI are stable:

1. Export production MySQL from legacy `save_net` (schema + data).
2. Run reconciliation scripts: row counts per table, checksum samples on `users.email`.
3. Map legacy IDs with `users.legacy_user_id` (or a dedicated staging table) for traceability.
4. Load order: `roles` / `permissions` (already seeded) → `users` → `user_profiles` → `role_user` → optional `activity_logs` backfill.
5. Validate in staging: login as sampled accounts, compare profile fields against legacy UI.
6. Cutover: maintenance window, final incremental sync, DNS/app switch.

This repository does not automate ETL yet; implement as Laravel Artisan commands or one-off SQL scripts against a **staging** database first.
