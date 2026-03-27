# Legacy Review Notes (`D:\app.saverack.com\save_net`)

## What was reviewed

- `database/migrations/2014_10_12_000000_create_users_table.php`
- `app/Models/User.php`
- `database/migrations/2021_11_23_225200_create_user_permissions_table.php`
- `database/migrations/2023_02_14_144721_create_section_permissions_table.php`
- `database/migrations/2022_05_17_115958_create_employee_permissions_table.php`
- `database/migrations/2021_07_27_103858_create_user_customers_table.php`
- `database/migrations/2021_08_05_110944_create_customer_users_table.php`
- `database/migrations/2021_08_02_184352_create_user_login_histories_table.php`
- `database/migrations/2021_07_26_020147_create_customers_table.php`

## Core problems confirmed in legacy

- Users table contains mixed concerns: auth + HR + payroll + comm channels + ACL flags.
- Plaintext password copy exists (`my_password`) and is read back in code paths.
- Role is a numeric flag on users (`role`) instead of FK relation.
- Permissions are stored as hundreds of boolean columns across multiple tables.
- Duplicated user/customer relationship tables (`user_customers`, `customer_users`) with repeated names.
- Audit/history is fragmented (`user_login_histories` plus many custom log writes).
- Model contains large business logic and raw SQL, causing fat model/controller architecture.

## Legacy fields to preserve (MVP scope)

### Auth + account identity
- `full_name`, `email`, `password`, `verified_at`, `remember_token`, `status`, `last_logged_in_at`, `ip_address`

### Access and user categorization
- `role`, `userType`, `crm_access`, `wh_access`, `is_permission`, `is_deleted`

### Contact/profile (already used heavily)
- `phone`, `avatar`, `skype`, `telegram`, `slack`, `slack_member_id`, `tag`

### Employment/HR fields currently in users
- `employeeType`, `hireDate`(derived/used), `birthday`, `pin`, `month`, `day`, `hours`, `full_hours`, `half_hours`
- `pto`, `pto_accrual_rate`, `sick_days`, `absence`, `holiday`, `remote`, `other`, `late`
- `salary`, `terminateDate`, `reason`, `punch_status`, `punch_time`, `lunch_status`, `manager_slack_channel`

### Compensation/commission fields currently in users
- `fulfillment_percent`, `referral_percent`, `prepay_percent`, `on_demand_percent`, `shipment_bonus`

## Normalized target mapping (recommended)

- `users` (auth + minimal profile)
  - id, name, email, password_hash, account_status, email_verified_at, last_login_at, last_login_ip, avatar_path
- `roles`
  - id, key, label
- `user_roles` (future-proof if users can hold multiple roles)
  - user_id, role_id
- `permissions` + `role_permissions`
  - replaces boolean-column permission tables
- `user_customer_access`
  - user_id, customer_id, access_level (replace `user_customers` + `customer_users`)
- `user_profiles`
  - user_id, phone, skype, telegram, slack, slack_member_id, tag, personal_email, address fields
- `employee_profiles`
  - user_id, employee_type, pin, birthday, hire_date, termination_date, termination_reason, manager_slack_channel
- `employee_time_policies`
  - user_id, hours, full_hours, half_hours, pto_balance, pto_accrual_rate, sick_days, absence_days, holiday_days
- `employee_compensations`
  - user_id, salary, fulfillment_percent, referral_percent, prepay_percent, on_demand_percent, shipment_bonus
- `activity_logs`
  - actor_user_id, action, subject_type, subject_id, metadata, ip_address, created_at

## Migration safety notes

- Never carry `my_password` into new schema.
- Keep a one-time encrypted import archive for legal traceability, then purge plaintext source field copies.
- Build a field mapping sheet before ETL:
  - legacy_table.legacy_field -> new_table.new_field
  - transform/default/null policy
  - data quality notes

