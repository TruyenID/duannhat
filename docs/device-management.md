---
title: Device Management and TMS API
category: reference
tags: [device, pairing, device-token, tms, device-type, device-status]
summary: Device pairing lifecycle (pairing code, device token, revoke), HQ and shop device CRUD endpoints, the TMS device API, and the devices table schema.
related: [api-devices, api-kds, api-kiosk, device-and-payment-management]
verified_at: 2026-07-30
source_of_truth: backend/app/Omnify/Enums/DeviceTypeEnum.php
---

# Device Management & TMS API

## Overview

System for managing terminal devices (tablets, kiosks) connected to TempoFast. Each device is attached to one specific branch and authenticates via a QR code pairing flow.

### Device Types

`DeviceTypeEnum` has **seven** cases, all in production. Payment-option scoping
per type is covered in
[Device Taxonomy & Device-Scoped Payment Options](guide/device-and-payment-management.md).

| Type | Description | Client |
|------|-------------|--------|
| `tms` | Table Management Terminal — table-management tablet | `app/tms/` (Expo) |
| `pos` | Point of Sale — cash register | `web/pos/` (Vite/React) |
| `kds` | Kitchen Display System — kitchen display | `app/kds/` (Vite/React PWA) |
| `workstation` | Restaurant workstation — LAN gateway, printers, offline-first | `workstation/` (Go + Wails) |
| `kiosk` | Self-service ordering kiosk | `app/kiosk/` (Expo) |
| `handy` | Handy terminal — server handheld order-taking | `app/handy/` (Expo); menu served by `GET /api/v1/workstation/menu/handy` |
| `self_regi` | Self-checkout register (セルフレジ) — money-collection point, own payment channel | kiosk surface (`device.auth:kiosk,self_regi`) |

### Device Lifecycle

```
pending_activation ──(pair)──→ active ←──→ inactive
                                  │
                                  └──(revoke)──→ revoked
```

| Status | Description |
|--------|--------|
| `pending_activation` | Newly created, not yet paired. QR/pairing code is shown to the admin. |
| `active` | Successfully paired and operational. |
| `inactive` | Temporarily disabled by admin. Device cannot call the API. |
| `revoked` | Permanently revoked. Token is invalidated. |

---

## Pairing Flow

```
┌──────────┐     ┌──────────┐     ┌──────────┐
│  Admin    │     │  Backend │     │  TMS App │
│(Frontend) │     │ (Laravel)│     │  (Expo)  │
└────┬─────┘     └────┬─────┘     └────┬─────┘
     │ POST /devices  │                │
     │───────────────→│                │
     │ {name, type,   │                │
     │  branch_id}    │                │
     │                │                │
     │ 201 + pairing  │                │
     │  _code (6 char)│                │
     │←───────────────│                │
     │                │                │
     │ Show QR code   │                │
     │ (contains      │                │
     │  pairing_code) │                │
     │                │                │
     │                │ Scan QR        │
     │                │←───────────────│
     │                │ POST /devices  │
     │                │   /pair        │
     │                │ {pairing_code, │
     │                │  device_info}  │
     │                │                │
     │                │ 200 + device   │
     │                │   _token       │
     │                │───────────────→│
     │                │                │
     │                │ Subsequent API │
     │                │  calls with    │
     │                │  Bearer token  │
     │                │←───────────────│
```

### Pairing Code

- 6 uppercase alphanumeric characters (e.g. `A3BK9X`)
- Expires after **15 minutes**
- Unique globally
- Cleared after a successful pair
- Admin can regenerate at any time

### Device Token

- 64 URL-safe random characters
- Long-lived (does not expire, must be revoked manually)
- Unique globally
- **NOT** exposed in the admin list/show API (security)
- Only returned in the `/devices/pair` response

---

## API Endpoints

### Admin — HQ scope (all branches)

Base: `GET /api/v1/hq/{brandSlug}/devices`

| Method | Path | Description |
|--------|------|--------|
| GET | `/devices` | List devices (paginated, filterable) |
| POST | `/devices` | Create device (auto-gen pairing code) |
| GET | `/devices/{id}` | Device detail |
| PUT | `/devices/{id}` | Update device |
| DELETE | `/devices/{id}` | Soft delete |
| POST | `/devices/{id}/restore` | Restore |
| POST | `/devices/{id}/regenerate-pairing` | Regenerate pairing code |
| POST | `/devices/{id}/revoke` | Revoke device (invalidate token) |
| GET | `/devices/{id}/signing-keys` | List the device's Ed25519 offline-signing keys (#1092) |
| POST | `/devices/{id}/signing-keys/{signingKey}/revoke` | Revoke one signing key — immediate and retroactive |
| GET | `/devices/lookup` | Dropdown data |
| POST | `/devices/bulk-delete` | Bulk soft delete |

**Filters** (query params for `GET /devices`):
- `search` — search by name
- `status` — filter by DeviceStatus enum
- `type` — filter by DeviceType enum
- `branch_id` — filter by branch
- `with_trashed` — include soft deleted
- `sort` — column sort (prefix `-` for desc, default: `-created_at`)
- `per_page` — pagination (default 25, max 100)

### Admin — Shop scope (1 branch)

Base: `GET /api/v1/shops/{shopSlug}/devices`

Same as HQ but automatically scoped to the branch (no `branch_id` filter needed).
No `bulk-delete` or `lookup` (use the HQ endpoints for cross-branch operations).

| Method | Path | Description |
|--------|------|--------|
| GET | `/devices` | List devices in this shop |
| POST | `/devices` | Create device (branch auto-set) |
| GET | `/devices/{id}` | Device detail |
| PUT | `/devices/{id}` | Update |
| DELETE | `/devices/{id}` | Soft delete |
| POST | `/devices/{id}/restore` | Restore |
| POST | `/devices/{id}/regenerate-pairing` | Regenerate pairing code |
| POST | `/devices/{id}/revoke` | Revoke |

### Public — Pairing

| Method | Path | Description |
|--------|------|--------|
| POST | `/api/v1/devices/pair` | Pair device with pairing code |

**Request:**
```json
{
  "pairing_code": "A3BK9X",
  "device_info": {
    "os": "iOS 18",
    "model": "iPad Pro 13",
    "app_version": "0.1.0"
  }
}
```

**Response (200):**
```json
{
  "device_token": "aB3kX9...64chars...",
  "data": {
    "id": "uuid",
    "name": "TMS-Reception",
    "type": "tms",
    "status": "active",
    "branch_id": "uuid",
    "branch": { "id": "uuid", "name": "渋谷店" },
    "paired_at": "2026-04-10T12:00:00Z"
  }
}
```

### TMS Device API

Base: `GET /api/v1/tms/*`

Authenticates via the `Authorization: Bearer <device_token>` header.
The `device.auth:tms` middleware checks device status = active & type = tms.

| Method | Path | Description |
|--------|------|--------|
| GET | `/tms/me` | Device info + branch |
| GET | `/tms/zones` | Zones in the device's branch |
| GET | `/tms/tables` | Tables with current status |
| POST | `/tms/tables/{table}/status` | Change table status |
| DELETE | `/tms/tables/{table}/call` | Clear a pending staff call on the table |

**`POST /tms/tables/{table}/status` Request:**
```json
{
  "status": "occupied",
  "note": "Party of 4"
}
```

---

## Schema

### Device table (`devices`)

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | PK |
| name | varchar(255) | Unique per branch |
| type | enum | tms, pos, kds, workstation, kiosk, handy, self_regi |
| status | enum | pending_activation, active, inactive, revoked |
| pairing_code | varchar(6) | Unique, nullable. Cleared after pair. |
| pairing_expires_at | datetime | Null after pair |
| device_token | varchar(64) | Unique, nullable. Set on pair. |
| paired_at | datetime | Timestamp of successful pair |
| last_seen_at | datetime | Updated on each API call (heartbeat) |
| device_info | json | OS/model/version reported by device |
| notes | text | Admin notes |
| organization_id | UUID | FK → organizations |
| branch_id | UUID | FK → branches |
| created_by_id | UUID | Audit |
| updated_by_id | UUID | Audit |
| created_at, updated_at, deleted_at | timestamp | Standard |

**Indexes:**
- UNIQUE: `pairing_code`, `device_token`, `(branch_id, name)`
- INDEX: `organization_id`, `(branch_id, status)`, `(branch_id, type)`

---

## Backend Files

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── HQ/DeviceController.php          ← Admin (all branches)
│   │   │   ├── Shop/DeviceController.php         ← Admin (single branch)
│   │   │   ├── Tms/TmsController.php             ← TMS device endpoints
│   │   │   └── Device/PairingController.php      ← Public pairing
│   │   ├── Middleware/
│   │   │   └── AuthenticateDevice.php            ← device.auth middleware
│   │   ├── Requests/
│   │   │   ├── DeviceStoreRequest.php
│   │   │   └── DeviceUpdateRequest.php
│   │   └── Resources/
│   │       └── DeviceResource.php                ← Hides device_token
│   ├── Models/
│   │   └── Device.php
│   ├── Policies/
│   │   └── DevicePolicy.php
│   ├── Services/Device/
│   │   └── DeviceService.php
│   └── Omnify/ (auto-generated, DO NOT EDIT)
│       ├── Enums/DeviceStatusEnum.php
│       ├── Enums/DeviceTypeEnum.php
│       └── Modules/Device/...
├── database/
│   ├── migrations/omnify/..._create_devices_table.php
│   └── factories/DeviceFactory.php
└── routes/
    ├── api.php                                    ← Registers pairing + TMS routes
    ├── api/hq/devices.php                         ← HQ device routes
    ├── api/shops/devices.php                      ← Shop device routes
    ├── api/tms.php                                ← TMS device routes
    ├── api/pos.php, kiosk.php, kds.php, handy.php ← other device surfaces
    └── api/workstation.php                        ← workstation routes

schemas/
├── Device/Device.yaml                             ← Omnify schema
└── Enum/
    ├── DeviceStatus.yaml
    └── DeviceType.yaml

web/admin/src/types/models/
├── Device.ts                                      ← Editable type
├── base/Device.ts                                 ← Auto-generated (zod, i18n, form builders)
└── enum/
    ├── DeviceStatus.ts
    └── DeviceType.ts
```

---

## TMS App (React Native / Expo)

App at `app/tms/`, in-tree.

Its login flow and tech stack are **owned by the app**, not by this page — they
change with its `package.json`, and a copy here drifts. Read
[`app/tms/README.md`](../app/tms/README.md) (§ Device Pairing, § Tech Stack).
