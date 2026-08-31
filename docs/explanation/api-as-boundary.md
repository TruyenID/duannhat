---
title: API as Boundary
category: explanation
tags: [api-design, architecture, rfc-7807, derived-fields, operation-oriented]
summary: Why thin APIs leak schema, force client recomputation, and lose audit clarity — and what plan-028 changed to thicken the KDS boundary into a proper command + resource + error catalog. Reads like a post-mortem; written for devs onboarding to the godx-tempo umbrella.
related: [kds-domain, api-kds, order-domain]
---

# API as Boundary

> "The API is the boundary between client and server. Everything on the server side — the database schema, the service layer, the queue, the cache — is implementation detail the client must never depend on. When you change the schema, only the API surface changes if the boundary holds. When you keep the boundary thin, every schema change is a client break."

Plan-028 reworked the KDS API based on this principle after the plan-027 senior review called gen-1 "API mỏng như tờ giấy" (paper-thin). This doc records the four shifts plan-028 made and why each matters — so the next domain we touch (TMS, kiosk, customer ordering) doesn't repeat the gen-1 mistakes.

---

## 1. The thin-API problem

The gen-1 KDS surface looked like this:

```
PATCH /api/v1/kds/orders/{order}/items/{item}/status
  body: { "status": "preparing" }

GET /api/v1/kds/orders
  returns: full DeviceModel + raw CustomerOrder rows (FK columns + all)
```

Five concrete failures:

1. **Schema leak.** `GET /kds/me` returned the raw `Device` Eloquent model — `organization_id`, `console_brand_id`, `console_organization_id`, the device token itself. The client knew about every FK column. Rename `organization_id` → `org_id` at the DB level, and the API breaks.
2. **Client-side derivation.** The frontend computed `aging_minutes = (Date.now() - opened_at) / 60_000`. Color thresholds (green/amber/red at 5/10 min) lived in `lib/utils.ts`. Two clients (godx-kds, pos-web staff dashboard) computed slightly different values from the same data because each had its own clock + its own rounding rule.
3. **No business rules.** Anti-misclick (cook taps mark-served too fast after mark-ready) was suggested in PRs but never enforced — the service accepted any transition, the controller was a 4-line passthrough.
4. **Generic errors.** `{ "message": "Validation failed" }` with no machine-readable code. The client `error.message` lookup was a string-match fragility.
5. **CRUD verb soup.** `PATCH /resource` with an arbitrary enum body is a poor command. Audit logs said "status changed"; permissions could only granular at the HTTP method level; no path to per-action permissions like "can mark-served but not revert."

---

## 2. API as Boundary — the four shifts

### Shift A: Hide DB schema, expose semantic resources

The new `KdsDeviceResource`, `KdsOrderResource`, `KdsItemResource` are explicit projections. They:

- **Hide** internal FK columns the client never needs (`organization_id`, `console_*_id`, `created_by_id`, `device_token`, `pairing_code*`).
- **Rename** DB-shaped fields to domain-shaped ones: `branch_id` becomes the embedded `branch: { id, name, code }` object.
- **Filter** rows the client shouldn't see: `KdsOrderResource::items` excludes voided items at serialize time, not in the database.
- **Always populate** derived fields: every order ships with `aging_minutes`, `is_late`, `priority`, `pending_items_count`, `oldest_pending_age_minutes`, `can_bump_all`. Items ship with `aging_minutes`, `time_in_current_status_seconds`, `is_blocked_by_toppings`, `allowed_transitions`, `started_preparing_at`, `ready_at`, `served_at`, and `toppings[]`.

The client renders what it's told. Rename a DB column, the Resource adapter changes, the API stays stable. Add a new computed field, every consumer sees it without redeploying.

### Shift B: Operation > Resource

Gen-1's single `PATCH status` became five operation endpoints:

```
POST /kds/orders/{}/items/{}/mark-preparing
POST /kds/orders/{}/items/{}/mark-ready
POST /kds/orders/{}/items/{}/mark-served
POST /kds/orders/{}/items/{}/revert
POST /kds/orders/{}/bump-all
```

Each is a verb + idempotency + business rules + audit row. Concretely:

- **Permission granularity.** Future code can `Gate::define('kds.item.mark-ready', ...)` independently of `kds.item.mark-served`. The HTTP method namespace was a dead end.
- **Audit clarity.** The `kds-bumps` log channel records `op_name = "mark_served"`, not `"status_changed"`. Postmortems can grep for the action.
- **Self-documenting.** A new dev reading `POST /mark-ready` immediately knows what it does. `PATCH status` requires reading the controller body.
- **Idempotency built-in.** Each operation requires `Idempotency-Key`; the response is cached for 24h. Retries are safe by default, not by hope.

### Shift C: Derived fields, not computed fields

The distinction:

- **Derived** = the server computes from authoritative state. Every consumer sees the same value at the same moment. Cloud + workstation use the same formula (see the COALESCE chain in [`api-kds.md`](../reference/api-kds.md#aging-anchors-time_in_current_status_seconds)).
- **Computed** = each client implements its own. Subject to clock skew, rounding drift, version skew across clients.

Plan-028 moved every aging / status / priority calculation to the server. The frontend reads `order.priority` and picks a color; it never computes priority from `opened_at`. The result: the late-count pill on the dashboard shows the same number as the workstation tablet shows as the staff manager dashboard shows, because all three read the same `meta.late_count`.

The `priorityColorClass()` helper in `app/kds/src/lib/utils.ts` is a pure presentation map — it accepts the enum and returns a Tailwind class. No business logic remains client-side.

### Shift D: Error catalog as part of the contract

Errors are not implementation accidents. plan-028's [`config/kds-errors.php`](../../backend/config/kds-errors.php) is the single source of truth:

```php
'KDS_E003' => [
    'type' => 'https://godx-tempo.dev/errors/kds/anti-misclick',
    'title' => 'Anti-misclick guard',
    'status' => 409,
    'remediation' => 'Đợi 30 giây sau khi mark-ready trước khi mark-served.',
],
```

Each `KdsRuleViolation` throw references a code; the exception handler emits RFC 7807 (`type`, `title`, `status`, `code`, `detail`, `context`, `remediation`). The frontend `error-toast.ts` maps each code to a toast variant + i18n key:

- `KDS_E001` (order finalized) → error toast + auto-refresh
- `KDS_E003` (anti-misclick) → warning toast "Quá nhanh, đợi 30s"
- `KDS_E004` (toppings parent not ready) → info with detail
- `KDS_E005` (throttle) → silent (anti-double-tap noise)

Adding a new business rule means adding `KDS_E009` to the catalog + a UX mapping. The shape stays stable; consumers never see a different error envelope. The workstation LAN handler still emits the simpler `{message}` envelope (DESIGN §6) — the FE parser handles both, falling back to a generic toast for LAN messages without a code.

---

## 3. When NOT to thicken

This pattern has costs. Don't apply it everywhere:

- **CRUD admin surfaces** (e.g., `/api/v1/hq/{brand}/product-types`) where the model IS the contract. Adding a resource layer + operation endpoints for a simple "edit row" UX is overkill. Stick with PATCH `resource/{id}` for admin tools.
- **Read-only firehose endpoints** where the client is intentionally close to the schema (e.g., a reporting export). The boundary still hides FKs, but no business rules need enforcement.
- **First-pass prototypes.** Build thin first; thicken once you've felt the pain. plan-027 shipped thin on purpose to learn the actual usage patterns before designing the operations.

The thickening cost is real: the cloud team wrote ~30 Pest tests for plan-028 covering business rules + Resource shape + idempotency + audit. The workstation Go side mirrored every formula. godx-kds rewrote 5 hooks + 1 component + 13 toast cases. **Don't pay this cost on surfaces where the client wasn't asking for a heavier contract.**

---

## 4. Checklist for the next domain

If you're designing an API for a new domain (TMS table operations, kiosk floor-staff escalation, customer ordering self-revert), ask:

1. **Schema visibility.** Does the response contain any DB column the consumer can't justify? If yes, write a Resource that hides it.
2. **Client-computed values.** Is the frontend computing aging, totals, status colors, or aggregate counts? Move them to the server.
3. **Operation vs CRUD.** Are different mutations doing semantically different things? Split them into operation endpoints. Are mutations conceptually "edit the row"? Keep PATCH.
4. **Business rules.** Where do the invariants live — controller, service, frontend? Pull them into a dedicated rules class invoked at the API boundary.
5. **Error codes.** Does the client need to react differently to different failure types? Define an error catalog. If every error is "show this string", a generic envelope is fine.
6. **Idempotency.** Will retries cause double-apply? Add `Idempotency-Key` header + cache.

If three or more answers point to "thicken", you're in plan-028 territory. Reach for `KdsBusinessRules` / `KdsItemResource` / `kds-errors.php` as templates.

---

## References

- [`docs/reference/api-kds.md`](../reference/api-kds.md) — endpoint surface, all 9 gen-2 endpoints, error catalog
- [`docs/explanation/kds-domain.md`](kds-domain.md) §5 — operation-oriented commands rationale
- plan-028 `DESIGN.md` — full spec (plan đã xoá khỏi cây #2188 — git history)
- plan-028 `TASKS.md` — atomic implementation tasks (plan đã xoá khỏi cây #2188 — git history)
- Backend `app/Services/Kds/KdsBusinessRules.php` — example rules class
- Backend `app/Http/Resources/Kds/` — three Resource adapters
- Backend `config/kds-errors.php` — error catalog YAML-equivalent
- Frontend `app/kds/src/lib/error-toast.ts` — code → toast UX mapping
