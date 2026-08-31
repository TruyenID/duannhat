---
title: Test Users
category: reference
tags: [auth, sso, test-users, roles, seeders, fixtures, local-development]
summary: Explains how test users are provisioned for TempoFast. The system has no local credential matrix — every user comes from the upstream console portal via SSO sync, with three roles (org-admin, org-manager, staff) seeded locally.
related: [interfaces, sso-authentication, authorization]
---

# Test Users

This document explains how user accounts are provisioned for local development and tests. TempoFast cannot create real users on its own — every user is owned by the upstream console portal and synced down via SSO.

## Authority

| Resource | Owned by | Synced into |
| -------- | -------- | ----------- |
| Organization | Console portal | `organizations` table |
| Brand | Console portal | `brands` table |
| Branch (Shop) | Console portal | `branches` table |
| User | Console portal | `users` table |
| Role assignment | Console portal | `role_user` pivot |

This means **`php artisan db:seed` alone cannot create a usable workforce account.** The user must authenticate through Platform so Tempo can provision the verified identity and organization role.

## Roles

`LocalDevSeeder` (`database/seeders/LocalDevSeeder.php`) creates the three role rows the application checks against. The seeder uses `firstOrCreate`, so it is safe to re-run.

| Slug | Name | Level | Notes |
| ---- | ---- | ----- | ----- |
| `org-admin` | Organization Admin | 100 | Full administration of products, inventory, and menus within the organization |
| `org-manager` | Organization Manager | 50 | Manages day-to-day catalog and inventory; can approve workflows |
| `staff` | Staff | 10 | Operational role with limited approval rights |

> **Note:** Role rows are created with `console_organization_id = null` so they act as global templates. Per-user role assignments are created on the console side and sync down through SSO. See [Authorization](../explanation/authorization.md) for the full permission matrix.

## Local development workflow

```text
1. Start backend  → http://localhost:5400         (docker compose up)
2. Start frontend → http://localhost:5430          (docker compose up)
3. Visit          → http://localhost:5430/login    → click SSO
4. Sign in via the console portal at least once
   ↳ Platform SSO provisions User, Organization, and Role rows
5. Run sample data seeder (optional, for catalog content):
       cd backend
       php artisan db:seed --class=LocalDevSeeder
```

After step 4, your console user exists in the local `users` table and you can log in repeatedly without re-running anything.

## Tests that need a user

Because there is no fixed local credential matrix, every Pest test that needs an authenticated user **must create one via factory** instead of relying on a seeded fixture:

```php
$user = User::factory()
    ->for(Organization::factory())
    ->create();

$user->roles()->attach(Role::where('slug', 'org-admin')->firstOrFail());

$this->actingAs($user);
```

This is true for both Feature tests (`tests/Feature/`) and Browser tests (`tests/Browser/`). Browser tests cannot use the real SSO flow during CI — they should mint a session via Sanctum directly using `actingAs` or an equivalent helper.

> **Warning:** Never call the console portal from tests. Tests must be hermetic; they create their own `User`, `Organization`, `Brand`, and `Branch` rows via factories.

## What is intentionally NOT in this repo

- **Pre-baked email/password fixtures** (`admin@local.test`, `manager@local.test`, …). The console portal owns auth, so embedded credentials would be useless.
- **A `LocalUsersSeeder` that hard-codes test accounts.** Adding one would create users that the console portal does not know about, breaking the next SSO login.

If a future change makes TempoFast run in `standalone` auth mode (see [Architecture](architecture.md)), update this document and add a guarded seeder at that time. Until then, console SSO is the only path.
