# IndexedDB Cache

godx-kds uses IndexedDB via the `idb` library to persist a snapshot of the latest orders fetch. This enables offline boot — the dashboard renders the last known state even when both workstation LAN and cloud are unreachable.

## Database schema

| Field | Value |
|---|---|
| Name | `kds_cache` |
| Version | 1 |
| Object stores | `orders_snapshot` (single-key) |

The `orders_snapshot` store uses a fixed key `"latest"` — saving overwrites. We don't need history (kitchen workflow only cares about current state).

## Snapshot shape

```ts
interface OrdersSnapshot {
  fetched_at: string;       // ISO 8601 timestamp
  orders: KdsOrder[];       // Same shape as /kds/orders response
}
```

## API

`src/lib/idb.ts` exposes 3 functions:

| Function | Purpose | Caller |
|---|---|---|
| `saveOrdersSnapshot(snap)` | Overwrites `latest` key with given snapshot | `useOrders` queryFn (background, fire-and-forget) |
| `loadOrdersSnapshot()` | Returns the latest snapshot or `null` | `DashboardPage` mount effect (fallback for offline boot) |
| `clearOrdersSnapshot()` | Deletes `latest` key | `AuthProvider` logout + global 401 handler |

All functions wrap operations in try/catch — silent failures so the live data path never breaks if IndexedDB unavailable (private browsing mode, quota exceeded, browser doesn't support, etc).

## Best-effort caching contract

- Save runs background — never blocks the query return
- Load returns null if cache miss OR if IDB unavailable — UI falls back to error state
- Clear runs on logout but isn't awaited — if it fails, next session re-uses cache only if same device still paired

## What's cached

Currently only orders snapshot. The `kds_cache` database name + version are intentionally future-proof for adding more stores.

## What's NOT cached

- Device info (kept in `localStorage` for synchronous access at boot)
- Settings (kept in `localStorage`: `kds_theme`, `kds_api_mode`, `kds_audio_enabled`)
- API responses other than `/orders` (live data)

## Offline boot flow

```
1. App mount
2. AuthProvider hydrates token from localStorage → state="paired" (after /me verify)
3. DashboardPage mounts → useOrders begins fetch + loadOrdersSnapshot() runs
4a. If useOrders succeeds → discard snapshot result, render from live data, save new snapshot
4b. If useOrders errors AND snapshot loaded → render snapshot orders with amber "offline snapshot · {timestamp}" banner
4c. If useOrders errors AND no snapshot → show "error / retry" full-screen state
```

The snapshot is also displayed if useOrders is initially `isError`. It's never shown when live data is available.

## Future: pending bumps queue (Phase 7+ deferred)

Currently when offline, the user can't bump items (the PATCH call fails, optimistic update rolls back). If kitchen feedback demands "queue bumps offline + replay when back online", add:

- New IDB store `pending_bumps` (FIFO queue)
- `useBump.onError` enqueues if network error (not 4xx/5xx)
- Background drain when `useOrders.isSuccess` first becomes true
- UI badge showing queued count

Not implemented because:
1. Plan-027 chose Approach A (cloud-fallback) which covers most outages
2. Kitchen workflow expects bumps to be confirmed (not lost) — queue adds complexity
3. YAGNI until real-world feedback demands

## Implementation files

- `src/lib/idb.ts` — wrapper
- `src/hooks/use-orders.ts` — save on success
- `src/providers/AuthProvider.tsx` — clear on logout/401
- `src/app/dashboard/page.tsx` — hydrate + banner

## Testing

`src/lib/__tests__/idb.test.ts` uses `fake-indexeddb/auto` for unit tests. Vitest setup imports the polyfill globally.

## See also

- Task 6.2 implementation commit `2d99ea3`
- `docs/FAILOVER.md` for the broader offline strategy
