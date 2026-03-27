# Legacy field mapping (save_net → new CRM)

This document anchors ETL work. Core auth lives on `users`; extended legacy attributes go to `user_profiles` and `legacy_fields` JSON where needed.

| Legacy (`users`) | New location | Notes |
|------------------|--------------|--------|
| `full_name` | `users.name` | |
| `email` | `users.email` | |
| `password` | `users.password` | hashed; do **not** migrate `my_password` |
| `verified_at` | `users.email_verified_at` | rename / cast |
| `status` (1/2/3) | `users.status` | map to `pending` / `active` / `inactive` |
| `last_logged_in_at` | `users.last_login_at` | |
| `ip_address` | `users.last_login_ip` or `user_profiles` | last login vs profile |
| `role` (numeric) | `role_user` + `roles` | map to `admin` / `staff` / future roles |
| `userType` | `user_profiles.user_type` | |
| `phone`, `skype`, `telegram`, `slack`, … | `user_profiles.*` | see migration |
| HR / commission columns | `user_profiles` or `legacy_fields` | overflow in JSON |
| `my_password` | _drop_ | plaintext secret; not migrated |

Password reset uses Laravel `password_reset_tokens` (already in default migrations).
