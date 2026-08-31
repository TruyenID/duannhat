---
title: {{title}}
category: reference
tags: [{{tags}}]
summary: {{summary}}
related: [{{related}}]
---

# {{title}}

{{lead_paragraph}}

## Endpoints

| Method | Endpoint | Description | Auth |
| ------ | -------- | ----------- | ---- |
| GET    | `/api/v1/{{resource}}` | List | Required |
| POST   | `/api/v1/{{resource}}` | Create | Required |
| GET    | `/api/v1/{{resource}}/{id}` | Show | Required |
| PUT    | `/api/v1/{{resource}}/{id}` | Update | Required |
| DELETE | `/api/v1/{{resource}}/{id}` | Delete | Required |

## Fields

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `id` | integer | — | Primary key |
| `key` | string | Yes | Unique identifier |
| `name` | string | Yes | Display name |
| `status` | enum | Yes | See [Enums](#enums) |
| `created_at` | datetime | — | ISO 8601 |
| `updated_at` | datetime | — | ISO 8601 |

## Request Examples

### Create

**Request:**

```http
POST /api/v1/{{resource}}
Authorization: Bearer {token}
Content-Type: application/json
```

```json
{
  "key": "example-key",
  "name": "Example Name",
  "status": "active"
}
```

**Response (201):**

```json
{
  "data": {
    "id": 1,
    "key": "example-key",
    "name": "Example Name",
    "status": "active",
    "created_at": "2026-04-07T10:00:00Z",
    "updated_at": "2026-04-07T10:00:00Z"
  }
}
```

### Update

**Request:**

```http
PUT /api/v1/{{resource}}/1
```

```json
{
  "name": "Updated Name"
}
```

**Response (200):**

```json
{
  "data": { "id": 1, "name": "Updated Name", "..." : "..." }
}
```

## Enums

### `status`

| Value | Description |
| ----- | ----------- |
| `draft` | Initial state |
| `active` | Live and available |
| `archived` | Hidden but preserved |

## Errors

| Status | Code | Meaning |
| ------ | ---- | ------- |
| 401 | `unauthenticated` | Missing or invalid token |
| 403 | `forbidden` | Authenticated but not authorized |
| 404 | `not_found` | Resource does not exist |
| 422 | `validation_failed` | Field validation errors |

## Related

- [API Overview](api-overview.md)
- [{{related_doc_label}}]({{related_doc_path}})
