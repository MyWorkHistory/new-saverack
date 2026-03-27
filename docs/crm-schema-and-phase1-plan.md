# CRM Rebuild - Phase 1 Schema and Architecture

## Guiding principle

We are reusing legacy data fields, but not legacy table structure.
The database is normalized and designed for future modules (clients, products, orders, pipelines).

## Core Entities (Phase 1)

### 1) users
- Stores user identity/auth data only.
- Key fields:
  - id (bigint, PK)
  - role_id (FK -> roles.id)
  - name
  - email (unique)
  - password
  - phone (nullable)
  - avatar_path (nullable)
  - status (active|inactive|suspended)
  - email_verified_at (nullable)
  - last_login_at (nullable)
  - last_login_ip (nullable)
  - remember_token
  - created_at / updated_at / deleted_at

### 2) roles
- Role catalog for authorization (Admin, Staff in MVP).
- Key fields:
  - id (PK)
  - name (unique, e.g. admin, staff)
  - label (display name)
  - description (nullable)
  - is_system (bool)
  - created_at / updated_at

### 3) permissions (recommended)
- Action-level permissions for scale beyond 2 roles.
- Key fields:
  - id (PK)
  - key (unique, e.g. users.view)
  - label
  - module (e.g. users, dashboard)
  - created_at / updated_at

### 4) permission_role (pivot)
- Many-to-many mapping between roles and permissions.
- Key fields:
  - role_id (FK -> roles.id)
  - permission_id (FK -> permissions.id)
  - assigned_at (nullable)
- Composite PK: (role_id, permission_id)

### 5) activity_logs (recommended)
- Tracks important actions for audit and dashboard metrics.
- Key fields:
  - id (PK)
  - user_id (nullable FK -> users.id, null when system)
  - action (e.g. user.created)
  - subject_type (polymorphic class name)
  - subject_id
  - description (nullable)
  - metadata (json, nullable)
  - ip_address (nullable)
  - user_agent (nullable)
  - created_at
- Indexes:
  - (subject_type, subject_id)
  - (action, created_at)
  - user_id

### 6) password_reset_tokens
- Native Laravel reset token storage.
- email (PK), token, created_at

### 7) personal_access_tokens
- Sanctum API token storage.

## Relationship Map

- Role 1 -> N Users
  - users.role_id references roles.id
- Role N <-> N Permission
  - permission_role pivot
- User 1 -> N ActivityLog
  - activity_logs.user_id references users.id (nullable with nullOnDelete)
- ActivityLog polymorphic target
  - subject_type + subject_id can reference user records now, and future modules later

## Why this is scalable

- Avoids duplicated auth/role data.
- Supports migration of all old fields into the right normalized table (or future extension table).
- Permission-based auth enables per-module feature rollout for future clients.
- Activity logs provide compliance/auditing and dashboard metrics without touching transactional tables.

## Legacy field migration strategy

1. Export all old CRM fields.
2. Build a field mapping matrix:
   - legacy_field -> new_table.new_column
   - transform rule (if any)
   - nullable/default policy
3. Keep unknown/rare legacy attributes in extension tables (not in users core) when needed.
4. Perform staged migration:
   - staging tables -> cleaned tables -> production tables
5. Validate row counts + checksum samples before cutover.

## Phase 1 API modules

- Auth API (Sanctum):
  - POST /api/auth/login
  - POST /api/auth/forgot-password
  - POST /api/auth/reset-password
  - POST /api/auth/logout
  - GET /api/auth/me
- Dashboard API:
  - GET /api/dashboard/summary
- Users API:
  - GET /api/users (search + pagination)
  - POST /api/users
  - GET /api/users/{id}
  - PUT /api/users/{id}
  - DELETE /api/users/{id}

## Frontend (Vue 3 + Tailwind template)

- Auth:
  - Login page
  - Forgot Password page
  - Reset Password page
- Dashboard:
  - Summary cards widget container
- Users:
  - Datatable view
  - Create/Edit modal or separate form page
  - Search + pagination controls

## Deployment model for Node <= 14 server

- Build frontend locally:
  - npm install
  - npm run build
- Copy compiled files to Laravel public assets.
- Serve everything through Laravel + web server.
- No Node runtime process on production server.

