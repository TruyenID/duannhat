# Flow Diagrams

ASCII sequence diagrams for key user journeys and system interactions in godx-kds. These show the happy paths and notable failure modes.

## Pairing Flow

6-char code exchange: admin creates device code → staff enters code → token saved locally.

```
Admin Web          Cloud            KDS Tablet
    │               │                    │
    ├─ Create KDS ──→ /devices          │
    │  device type   Generate code      │
    │               (15 min TTL)         │
    │                 │                  │
    │                 │    QR + code      │
    │                 │<─ displayed       │
    │                 │                   │
    │                 │   Browser opens   │
    │                 │   kds-web URL     │
    │                 │←─────────────────│
    │                 │                   │
    │                 │  Pairing page     │
    │                 │  Staff types code │
    │                 │←─────────────────│
    │                 │                   │
    │                 │ POST /devices/pair
    │                 │ {pairing_code}    │
    │                 │←─────────────────│
    │                 │                   │
    │                 │ 200 OK            │
    │                 │ {device_token,    │
    │                 │  device_info}     │
    │                 │──────────────────→│
    │                 │                   │
    │                 │                   │ save localStorage
    │                 │                   │ kds_device_token
    │                 │                   │ kds_device_info
    │                 │                   │
    │                 │                   │ redirect /
    │                 │                   │→ Dashboard
    │                 │                   │
```

## Bump Flow (LAN Mode)

Kitchen staff bumps item status. Two taps per item: tap 1 = preparing, tap 2 = ready. Workstation is primary, cloud is standby.

```
KDS Tablet         Workstation       Cloud            Other KDS
    │                  │              │               tablets
    │ Tap 1 "Nấu"      │              │                 │
    ├─ optimistic      │              │                 │
    │  update local    │              │                 │
    │                  │              │                 │
    │ POST /api/v1/kds/orders/123
    │ /items/5/mark-preparing         │                 │
    │─────────────────→│              │                 │
    │                  │              │                 │
    │                  │ Check idempotency cache
    │                  │ (key: device_id + idem_key)    │
    │                  │                 │                 │
    │                  │ UPDATE order_items.status
    │                  │ ghi started_preparing_at        │
    │                  │ Broadcast WS event              │
    │                  │─────────────────→│                 │
    │                  │                  │ (fallback only) │
    │                  │  200 OK response │                 │
    │←─────────────────│                  │                 │
    │                  │                  │                 │
    │ Tap 2 "Xong"     │                  │                 │
    │ POST mark-ready  │                  │                 │
    │─────────────────→│                  │                 │
    │                  │ UPDATE status=ready              │
    │                  │ ghi ready_at                    │
    │                  │ Broadcast WS event              │
    │                  │──────────────────────────────────→│
    │                  │                  │ All devices see
    │                  │                  │ status_changed
    │                  │                  │ event
    │                  │                  │
    │ (async)          │ POST /workstation/sync-up
    │                  │─────────────────→│
    │                  │                  │ Cloud authoritative
    │                  │                  │ Merge (no conflict)
    │                  │                  │ Broadcast Reverb
    │                  │                  │ (no-op if LAN active)
    │                  │                  │
    │                  │←─────────────────│ 200 OK
    │                  │                  │
    │ [Waiter thấy     │                  │
    │  status=ready,   │                  │
    │  lấy món ra bàn] │                  │
    │                  │                  │
```

## Bump Flow (Cloud-Fallback Mode)

Workstation unreachable (30s timeout triggered). KDS fall back to cloud direct.

```
KDS Tablet         Workstation       Cloud            Other KDS
    │              (unreachable)       │               tablets
    │ Tap "Ready"                      │                 │
    ├─ optimistic update               │                 │
    │                                  │                 │
    │ POST /api/v1/kds/orders/123/... │                 │
    │ items/5/mark-ready (cloud, retry)│                 │
    │──────────────────────────────────→│                 │
    │                                  │                 │
    │ (no workstation response)         │                 │
    │ cloud authoritative              │                 │
    │                                  │ UPDATE status   │
    │                                  │ Broadcast Reverb│
    │                                  │─────────────────────→│
    │                                  │                 │
    │ 200 OK                           │                 │
    │←──────────────────────────────────│                 │
    │                                  │ All devices see │
    │ (no workstation sync UP          │ via Reverb      │
    │  needed — cloud is source)        │                 │
    │                                  │                 │
```

## Reconnect Flow

WebSocket drops (network glitch or workstation crash). Client reconnects with exponential backoff.

```
KDS Tablet         Workstation       Cloud
    │                  │              │
    │ WS open          │              │
    │ auth_ok          │              │
    │←─────────────────│              │
    │                  │              │
    │ (listening)      │              │
    │                  │              │
    │ Network glitch   │              │
    │ WS closes        │              │
    │──────────────────x              │
    │                  │              │
    │ Backoff 1s       │              │
    │ (wait)           │              │
    │                  │              │
    │ Retry open       │              │
    │─────────────────→│              │
    │ (workstation still down)         │
    │                  │              │
    │ close 1000       │              │
    │←─────────────────│              │
    │                  │              │
    │ Backoff 2s       │              │
    │ (wait)           │              │
    │                  │              │
    │ Retry open       │              │
    │─────────────────→│              │
    │                  │              │
    │ Send auth {token}               │
    │─────────────────→│              │
    │                  │ Validate via cloud
    │                  │──────────────→│
    │                  │ 200 OK        │
    │                  │←──────────────│
    │                  │              │
    │ auth_ok          │              │
    │←─────────────────│              │
    │                  │              │
    │ (listening)      │              │
    │ Backoff resets   │              │
    │ to 1s            │              │
    │                  │              │
```

## Failover Flow

Workstation becomes unreachable after 30s. KDS marks workstation down and routes all subsequent requests to cloud.

```
KDS Tablet         Workstation       Cloud
    │                  │              │
    │ resolveBaseUrl() │              │
    │ via="workstation"│              │
    │                  │              │
    │ GET /orders      │              │
    │─────────────────→│              │
    │                  │              │
    │ (no response)    │              │
    │ 30s timeout      │              │
    │                  │              │
    │ markWorkstationUnreachable()
    │ via="cloud"      │              │
    │                  │              │
    │ GET /orders      │              │
    │───────────────────────────────→│
    │                  │              │
    │                  │              │ 200 OK
    │←───────────────────────────────│
    │                  │              │
    │ (all subsequent calls to cloud) │
    │                  │              │
    │ 30s backoff window              │
    │ (retry workstation every 30s)   │
    │                  │              │
    │ (workstation comes back online) │
    │ retry succeeds   │              │
    │─────────────────→│              │
    │ 200 OK           │              │
    │←─────────────────│              │
    │                  │              │
    │ via="workstation"(resume)       │
    │                  │              │
```

## Token Revoke Flow

Admin revokes device token in admin-web. Tablet detects revocation on next API call.

```
Admin Web          Cloud            KDS Tablet
    │               │                    │
    │ Unpair device │                    │
    │ DELETE /devices/:id
    │──────────────→│                    │
    │               │                    │
    │ 200 OK        │                    │
    │←──────────────│                    │
    │               │                    │
    │               │   (KDS still has   │
    │               │    cached token)   │
    │               │                    │
    │               │   GET /orders      │
    │               │←───────────────────│
    │               │                    │
    │               │   401 Unauthorized │
    │               │───────────────────→│
    │               │                    │
    │               │  Global 401 handler
    │               │  Clear localStorage
    │               │  state="unpaired"
    │               │  Redirect /pairing │
    │               │                    │
    │               │   (tablet returns  │
    │               │    to pairing form)│
    │               │                    │
```

## Offline Boot Flow

App open with no network connection. IndexedDB provides snapshot (Phase 6+).

```
KDS Tablet         Cloud            Local
    │                │             DB (IDB)
    │ App boot       │                │
    │                │                │
    │ GET /me        │                │
    │ (network error)│                │
    │–x──────────────│                │
    │                │                │
    │ offline: true  │                │
    │ state="paired" │                │
    │ (cached info)  │                │
    │                │                │
    │ GET /orders    │                │
    │ (network error)│                │
    │–x──────────────│                │
    │                │                │
    │ Fallback to    │                │
    │ IDB snapshot   │                │
    │───────────────────────────────→│
    │←───────────────────────────────│
    │ Orders from    │                │
    │ last sync      │                │
    │                │                │
    │ [amber banner] │                │
    │ "Offline       │                │
    │  snapshot"     │                │
    │                │                │
    │ Staff can tap  │                │
    │ items (enqueue │                │
    │ to sync queue) │                │
    │                │                │
    │ Network back   │                │
    │ resolveBaseUrl │                │
    │ retries        │                │
    │───────────────→│                │
    │ 200 OK         │                │
    │←───────────────│                │
    │                │                │
    │ Banner removed │                │
    │ Sync queue UP  │                │
    │ Complete       │                │
    │                │                │
```

## Connection Badge State Machine

The "Connection" badge in the top-right corner reflects realtime connectivity status (Phase 5+).

```
                   ┌─────────────┐
                   │  Connecting │ (WS handshake)
                   └─────┬───────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
         ▼               ▼               ▼
    ┌────────┐  (auth_ok) ┌──────────┐  (timeout)
    │  LAN   │─────────→  │ Ready    │  ┌──────────┐
    │  Open  │            └──────────┘  │ Fallback │
    └────────┘                 ▲        └──────────┘
         │                     │              │
    (network down)             │          (auth_fail)
         │                (WS close)       │
         └──────────────→ │←────────────────┘
                     ┌──────────┐
                     │ Backoff  │
                     │ Retry 1s │
                     └──────────┘
                         │
                  (exponential backoff
                   1s, 2s, 4s... 30s max)
```

## See Also

- [ARCHITECTURE.md](ARCHITECTURE.md) — System topology and data flow concepts
- [REALTIME.md](REALTIME.md) — WebSocket protocol details, Reverb setup
- [FAILOVER.md](FAILOVER.md) — Detailed failover behavior and auto-fallback
- umbrella **plans/plan-027/** — Full design spec
