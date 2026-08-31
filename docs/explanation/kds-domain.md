---
title: KDS Domain
category: explanation
tags: [kds, kitchen, order-item, state-machine]
summary: KDS works on the customer_order_items lifecycle (pending→preparing→ready→served, plus voided through POS only). KDS controller restricts to forward-or-lateral transitions in [preparing, ready, served]; service-level allows free transitions between active statuses for SSO admin path. Single KDS per branch shows all active orders.
related: [api-kds, order-domain, device-management]
---

# KDS Domain

This document explains the Kitchen Display System (KDS) domain — how order items flow through the kitchen lifecycle, the state machine rules, and why the system is designed with this architecture.

For endpoint details, see the [KDS API](../reference/api-kds.md) reference.

---

## Core Concepts

### Ticket and Items

In KDS terminology:
- **Ticket** = a single `CustomerOrder` record
- **Items** = the `CustomerOrderItem` rows attached to that order
- **Status** = each item has a lifecycle status: `pending`, `preparing`, `ready`, `served`, or `voided`

A single order can contain multiple items (e.g., 2 ramen, 1 salad, 1 miso soup). KDS shows all items from all active orders in the branch, grouped by order ticket for the kitchen staff to work through.

### KDS Device Scope

A single KDS device is assigned to one **branch**. It has **read access to all orders** in that branch with status `open`, `dining`, `checkout`, or `paying` (closed and voided orders are archived). It can **write** (bump status) on any item in those orders.

This means:
- One KDS per restaurant location is typical
- Multiple KDS devices can be paired to the same branch for redundancy or different station displays (future: filtering by kitchen zone)
- All KDS devices in a branch see the same order list and receive realtime updates via Reverb broadcast

---

## State Machine

### Item Status Lifecycle

```
pending ──→ preparing ──→ ready ──→ served
  │                                     ▲
  │                                     │
  └─────────────────────────────────────┘
        (linear forward path)

pending ──→ voided                      (POS only, separate endpoint)
```

| Status | Meaning | Set By | Transition |
|--------|---------|--------|------------|
| `pending` | Item is in the kitchen queue, not yet started. Initial state for new items. | Service (on item add) | Source state only — KDS cannot set it. |
| `preparing` | Kitchen staff has picked up the ticket and begun preparing the item. | KDS / Service | KDS can set from `pending`. Service allows from any active status. |
| `ready` | Item is finished and waiting for pickup or plating. | KDS / Service | KDS can set from `pending` or `preparing`. Service allows from any active status. |
| `served` | Item has been delivered to the guest. Final state before completion. | KDS / Service | KDS can set from any active status. Service allows from any active status. |
| `voided` | Item is cancelled (e.g., guest changed mind, POS error). Not set by KDS. | POS only | POS calls a separate void endpoint. |

### Controller Layer vs. Service Layer

**KDS Controller (`/api/v1/kds/orders/.../status`)** enforces strict transition rules:
- Validates status is in enum `[preparing, ready, served]`
- Rejects `pending` — KDS cannot set initial state
- Rejects `voided` — only POS can void items
- Delegates to `CustomerOrderService::updateItem()`

**Service Layer (`CustomerOrderService::updateItem()`)** is more permissive:
- Allows free transitions between active statuses (`pending`, `preparing`, `ready`, `served`)
- Used by SSO admin paths (admin-web dashboard to manually correct kitchen state)
- Does not enforce forward-only semantics
- Fired by both KDS controller and admin endpoints

This separation allows:
- KDS tablet apps to follow strict workflow (never go backward)
- Admin dashboards to fix mistakes or override stuck items
- Potential future kitchen zone filtering or staff role-based rules

---

## Why This Design?

### Branch-Scoped Channel

Each KDS device subscribes to `private-branch.{branchId}.kds-events`. Why branch-scoped instead of device-scoped?

- **Multi-device convergence**: If a restaurant has 2 KDS displays (bar station + grill station), both see the same orders. When one bumps an item to `ready`, the other sees it instantly. No race conditions.
- **No state syncing required**: Devices don't track each other's state. All state lives on the server. Events are the source of truth.
- **Scalability**: Adding a 3rd or 4th KDS is just another subscriber to the same channel. No new infrastructure.
- **Fallback ready**: If one KDS goes offline, the others continue. Catching up is a simple `/orders` list request.

### Idempotency and Retries

Idempotency-Key (24h cache TTL) is required for all status mutations because:

- **Network flakiness**: WiFi on restaurant tablets is unreliable. A bump might succeed but the response lost in transit. Without idempotency, retry would create a duplicate event.
- **Browser devtools**: QA or staff might accidentally double-click "Ready". Idempotency cache returns the same response, not a 409 conflict.
- **Replay safety**: Event flow (KDS → Cloud → other KDS via Reverb) is not immediately consistent. Clients send bumps optimistically and then wait for the Reverb broadcast echo as confirmation. The idempotency key deduplicates echoes.

The 24-hour TTL is long enough for any reasonable service interruption (restaurant downtime, device restart) but short enough to avoid stale cache issues across service deployments.

### Event-Driven Architecture

Why emit `OrderItemStatusChanged` instead of just returning the updated item?

- **Realtime broadcast**: All KDS devices learn about the change simultaneously (~100ms), not via polling.
- **Audit trail**: The event is logged with full context (who, when, which device). Future compliance/analytics can consume these events.
- **Future subscribers**: Other apps (POS dashboard, customer web, workstation) can subscribe to the same channel for kitchen status visibility.
- **Eventual consistency**: Client state converges to server state via events, not point-in-time responses.

---

## Multi-Device Convergence

When multiple KDS devices are paired to the same branch:

1. **Device A bumps item X to `ready`** → `PATCH /api/v1/kds/orders/{order}/items/{X}/status`
2. **Backend updates DB** → item X status = `ready`
3. **OrderItemStatusChanged event fires** → dispatched after DB commit (ShouldDispatchAfterCommit)
4. **Reverb broadcasts to channel** → `private-branch.{branchId}.kds-events`
5. **Device A and Device B receive event** → via Reverb WebSocket
6. **Both devices update local UI** → item X now shows `ready` on both screens

If the network is slow and Device A doesn't receive the Reverb echo within a timeout, it can call `GET /kds/orders` to re-fetch the full state — all status changes are reflected.

---

## Conflict Resolution Sketch

**Scenario**: POS voids an item while KDS is bumping it to `ready`.

### Current (Phase 1)

If a race occurs:

1. KDS sends `PATCH /kds/orders/{order}/items/{X}/status` with `status: ready`
2. Meanwhile, POS sends `POST /orders/{order}/items/{X}/void` (separate endpoint)
3. Whichever reaches the DB first wins; the second may fail with a 409 or 422 depending on the order of operations

This is a known gap. The KDS spec allows it, but the service layer does not explicitly handle the "item is being voided" case.

### Phase 2 Future Work

**FAILOVER** documentation will cover:
- Pessimistic locking strategy (lock the item row before checking status)
- Event versioning (to detect stale updates)
- Client-side optimistic reversal (if KDS gets a voided event unexpectedly)
- Retry-with-backoff for transient conflicts

For now, restaurants should avoid simultaneous POS void + KDS bump by workflow discipline (POS staff marks void clearly, kitchen sees it in real-time via Reverb and stops working the item).

---

## Item Timestamps

### `served_at`

When an item transitions to the `served` status, the service layer sets `served_at = now()`. This is used for:
- **Kitchen performance analytics**: How long from `pending` to `served`?
- **Broadcast payload**: The event includes `served_at` so clients know the exact moment of service
- **Reconciliation**: Workstation and Cloud can verify served timestamp matches between replicas

### `voided_at`

When an item is voided (via POS only), `voided_at = now()`. Future: may be populated by KDS if workflow allows staff to mark items "can't make" or "mistake order".

---

## Integration with Order Domain

KDS is a "sub-domain" of the Order domain. It operates on `CustomerOrderItem` rows:

- **Order statuses** (`open`, `dining`, `checkout`, `paying`, `closed`, `voided`) control which orders appear in KDS
- **Item statuses** (`pending`, `preparing`, `ready`, `served`, `voided`) are the KDS workflow
- **Order closure**: When the last item is `served` and payment is collected, the order moves to `closed` (POS domain)

KDS does not touch order-level status — it only mutates item status. The order remains in `dining` even if all items are `served`; it transitions to `checkout` or `paying` when payment happens.

---

## Future Considerations

### Kitchen Zones

Phase 2 may introduce **zone-scoped filtering** — a single KDS display shows only items for its assigned zone (grill, sauté, plating, etc.). This would:

- Require a `zone_id` on the `KdsDevice` table
- Filter `/kds/orders` to return items assigned to the device's zone
- Keep the same Reverb broadcast (all items, all zones, but devices filter client-side or via query)

### Staff Authentication

Currently KDS is device-scoped (no user authentication). Phase 2 might add optional staff login to track "who marked this item ready?" for performance metrics and accountability.

### Prep Times and Alerts

Future KDS might include:
- Item prep time SLA (e.g., "appetizers ready in 5 min, entrées in 15 min")
- Visual timer on each item
- Alert if an item is pending > SLA
- Auto-escalation to manager if grill is backed up

These are all client-side features and require no backend changes beyond what's already shipped.

---

## §5 Operation-oriented commands (plan-028)

The gen-1 API exposed a single mutation: `PATCH /kds/orders/{}/items/{}/status` with a `{status: enum}` body. The senior review on plan-027 called this "API mỏng như tờ giấy" — pass-through CRUD that pushed business logic, derived state, and audit semantics to the client. plan-028 thickened the boundary by splitting the mutation surface into five **operations**:

```
mark-preparing   (pending     → preparing)
mark-ready       (preparing   → ready)
mark-served      (ready       → served)    + 30s anti-misclick guard
revert           (any         → previous)  + served-is-terminal rule
bump-all         (bulk pending|preparing → next)
```

### Why operations instead of state mutations

1. **Each endpoint is a verb.** Permissions become granular: `kds.item.mark-ready` is not `kds.item.mark-served`. Audit logs say "mark_served" instead of "status_changed → 'served'" — the *intent* survives in the record.
2. **Business rules live at the boundary.** Anti-misclick (30s after `ready_at`), throttle (1/3s per item-device), toppings parent dependency, and branch isolation are enforced server-side via [`KdsBusinessRules`](../../backend/app/Services/Kds/KdsBusinessRules.php). The client cannot construct an invalid request.
3. **Derived fields are server-side.** `aging_minutes`, `is_late`, `priority` (normal/warning/critical), `time_in_current_status_seconds`, `allowed_transitions` all come from the response. The client renders what it's told — see [`api-as-boundary.md`](api-as-boundary.md) for the philosophy.
4. **RFC 7807 errors carry stable codes.** `KDS_E001`–`KDS_E008`. UX maps codes to toast variants (silent for E005 throttle, warning for E003 anti-misclick, info for E004 toppings, error for finalization/branch/transition). Catalog at [`backend/config/kds-errors.php`](../../backend/config/kds-errors.php).

### Where the gen-1 PATCH stands

It still works — gen-1 clients during the migration window get `Deprecation: true` + `Sunset: 2026-07-12` headers + a `Link` to the gen-2 successor. Internally the controller redispatches to the matching gen-2 method so business rules apply uniformly. Plan-028 Phase 8 PR will track the deprecation in `INTEGRATION_GAPS.md`.

### Bulk operation: bump-all + dedup

`POST /kds/orders/{order}/bump-all` body `{scope: pending|preparing}` advances every matching item in one transaction. Cloud dispatches **one event per affected item**, each carrying a derived `idempotency_key` of the form `` `${batchKey}:${itemId}` `` (where `batchKey` is the caller's `Idempotency-Key` header). The godx-kds `useBumpAll` hook pre-records that exact format for every item it expects bumped — read from QueryClient cache before the optimistic update mutates statuses. The backend Pest test `BumpAllTest::"bump-all broadcasts OrderItemStatusChanged with idempotency_key …"` and the FE integration test `dedup-integration.test.tsx` lock the format on both sides so the contract can't drift silently.

### Workstation LAN parity

Workstation (`workstation/internal/handler/local_kds_ops.go`) mirrors all five gen-2 endpoints + the COALESCE chain for `time_in_current_status_seconds`. The two intentional deviations:

- Error envelope is `{message}` not RFC 7807 (LAN simplicity per DESIGN §6); FE parses both.
- `is_blocked_by_toppings` is always `false` because workstation has no local `order_item_toppings` table yet (tracked as tech debt #294).

Otherwise the FE consumes either path identically. See [api-kds.md](../reference/api-kds.md) for the endpoint surface.
