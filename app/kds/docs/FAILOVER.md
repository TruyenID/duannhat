# Failover

LAN-first with cloud-fallback semantics. KDS connects to workstation by default (low latency, offline-tolerant for the restaurant network) and falls back to cloud direct when workstation is unreachable.

## Approach A (per plan-027 spec)

Per session decision, KDS implements **Approach A — twin namespace, full failover**: both workstation LAN and cloud expose mirror endpoints at `/api/v1/kds/*`. KDS uses `resolveBaseUrl()` runtime resolver to swap base URLs.

Visual:

```
                  ┌──────────────┐
                  │   KDS app    │
                  └──────┬───────┘
                         │ resolveBaseUrl()
              ┌──────────┴──────────┐
       LAN    │                     │  Cloud-fallback
       OK     ▼                     ▼  (LAN unreachable)
  ┌──────────────────┐    ┌──────────────────┐
  │  Workstation     │    │  Cloud Laravel    │
  │  :8080           │    │  /api/v1/kds/*    │
  │  /api/v1/kds/*   │    │  + Reverb WS      │
  └──────────────────┘    └──────────────────┘
            │                         │
            └─────────┬───────────────┘
                      │ sync UP / pull DOWN
                      ▼
              ┌──────────────────┐
              │  Cloud (auth.)   │
              └──────────────────┘
```

## Resolver mechanics

Implementation: `src/services/base-url-resolver.ts` (ported from pos-web).

### Modes
- `auto` (default) — try workstation first; on unreachable, fall back to cloud with a 30s backoff before retrying workstation
- `workstation` — force workstation, no fallback (Settings override)
- `cloud` — force cloud, skip workstation entirely (Settings override)

### Backoff (auto mode)
- `markWorkstationUnreachable()` called from `apiFetch` on network error
- Subsequent `resolveBaseUrl()` returns cloud URL until backoff window (30s) expires
- User-triggered mode change calls `resetUnreachable()` to retry immediately

## Read flow

| Source | Path | Cadence |
|---|---|---|
| Active orders | `GET /api/v1/kds/orders` | useOrders hook: 5s staleTime, 15s polling when WS down |
| Device heartbeat | `GET /api/v1/kds/me` | useMe hook: 30s polling for revoke detection |

Both endpoints exist on workstation AND cloud — resolver picks transparently.

## Write flow (KDS bump)

```
KDS click → useBump mutation
            ├─ records idempotency_key for echo dedup
            ├─ optimistic update of orders cache
            └─ PATCH /api/v1/kds/orders/{o}/items/{i}/status
                    via resolveBaseUrl()
                    │
       LAN mode  ─→ workstation     │  Cloud-fallback mode ─→ cloud
                    │ updates local │     │ updates cloud authoritative
                    │ replica       │     │
                    │ + WS broadcast│     │ + Reverb broadcast
                    │ + sync UP     │     │
                    │   queue       │     │
                    ▼               │     ▼
                  Cloud authoritative  ───────┘
```

## Conflict resolution

**Sync UP 409 (cloud rejects workstation's pending bump)**

Implemented in workstation Task 2.7 — `KdsBumpSyncHandler` on 409:
1. UPDATE workstation's local `order_items` to revert status + roll back `updated_at`
2. Broadcast `order_item.status_changed` event with `source:"revert"` so KDS UI reflects accurate state
3. Mark queue entry terminal (no retry)

**Self-echo dedup (Phase 5 Tasks 5.3+5.4)**

useBump records every bump's idempotency_key. RealtimeProvider's 30s TTL Set checks incoming events against recorded keys — skips invalidate for own echoes (already optimistically applied to cache).

**Token revoke detected mid-shift**

useMe heartbeat (30s) hits `GET /me`. 401 triggers global handler → AuthProvider clears token + cache → redirects to /pairing.

## Realtime failover

`createRealtimeDispatcher({mode, token, branchId})` picks:
- `mode: "workstation"` → `LanWsClient` (WS to workstation /ws, first-message auth)
- `mode: "cloud"` → `CloudEchoClient` (Laravel Echo / Pusher via Reverb)

Same `RealtimeBackend` interface — listeners don't care which.

On connection drop:
- LAN: exponential backoff 1s→30s, 4401 stops retry (token bad)
- Cloud Reverb: Pusher.js auto-reconnect

When resolver swaps mode (auto-fallback after 30s backoff window expires), RealtimeProvider's useEffect re-runs because `state` or branchId dependencies — old client closes, new client connects.

## Offline boot

Task 6.2 — `useOrders` persists every successful response to IndexedDB. Dashboard hydrates from cache if first fetch fails (no API, both LAN+cloud unreachable). Shows amber "offline snapshot" banner with timestamp.

Bumps are disabled implicitly when API unreachable — `useBump` mutation will throw on network error, optimistic update rolls back, user sees no change. Phase 7+ could add explicit bump queue (defer until UX feedback demands it).

## Implementation files

- `src/services/base-url-resolver.ts` — resolver + backoff
- `src/lib/api.ts` — apiFetch wraps resolveBaseUrl + markWorkstationUnreachable on network error
- `src/services/realtime/dispatcher.ts` — picks LAN/Cloud realtime backend
- `src/providers/RealtimeProvider.tsx` — wires backend → query cache invalidation
- `src/hooks/use-bump.ts` — records idempotency key + optimistic update
- `src/lib/idb.ts` — offline snapshot cache (Task 6.2)
- `src/app/dashboard/page.tsx` — offline snapshot hydration UI

## See also

- Plan-027 DESIGN.md §1 (Approach A overview)
- Plan-027 DESIGN.md §2 (conflict resolution matrix)
- [REALTIME.md](REALTIME.md) (Phase 5 realtime architecture)
- workstation Task 2.7 (sync UP 409 revert path)
