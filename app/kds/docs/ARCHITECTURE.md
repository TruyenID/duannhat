# Architecture

High-level design of godx-kds: tablet kitchen display system (KDS). This document covers system topology, component nesting, data flow patterns, API dependencies, routing, and cross-references to supporting docs.

## System Topology

godx-kds is a **LAN-first, cloud-fallback PWA** that runs on restaurant kitchen tablets.

```
┌─────────────────────────────────────────────┐
│  Cloud (Laravel API + Reverb broadcaster)  │
│  /api/v1/devices/pair — pairing             │
│  /api/v1/kds/{me, orders, ...} — fallback   │
│  Reverb private channel — realtime events   │
└──────────┬──────────────────────────────────┘
           │ Cloud fallback (30s timeout)
    ┌──────┴──────┐
    │ resolveBaseUrl()
    │ (LAN vs cloud)
    └──────┬──────┐
           │      │
    LAN (primary) │
    mDNS discovery│
           │      │
    ┌──────▼──────▼──────────────────────────┐
    │ Workstation (Go + Wails) at :8080      │
    │ HTTP API: /api/v1/kds/{me, orders,...} │
    │ WebSocket: /ws (first-message auth)    │
    │ EventBus: branch-scoped broadcast      │
    └──────────────────────────────────────┘
           │ mDNS (_workstation._tcp.local)
           ▼
    ┌──────────────────────────┐
    │  godx-kds (React PWA)    │
    │  Kitchen tablet browser  │
    │  IndexedDB offline cache │
    └──────────────────────────┘
           │
    ┌──────▼───────┐
    │ Star SDK     │
    │ Printers     │
    │ TCP/USB      │
    └──────────────┘
```

**Authentication & Discovery:**
1. Admin creates KDS device in admin-web (pairing code generation).
2. Tablet browser opens kds-web URL.
3. Device enters 6-char pairing code → cloud `/api/v1/devices/pair` → token + device info stored locally.
4. Subsequent requests route through `resolveBaseUrl()`: tries workstation LAN (mDNS) first, falls back to cloud after 30s timeout.

## Provider Nesting Tree

React context providers are nested in this order (outermost to innermost):

```
<ErrorBoundary>
  <BrowserRouter>
    <RealtimeProvider>
      <QueryProvider>
        <AuthProvider>
          <I18nProvider>
            <AppProvider>
              <Routes>
                <Page />
              </Routes>
            </AppProvider>
          </I18nProvider>
        </AuthProvider>
      </QueryProvider>
    </RealtimeProvider>
  </BrowserRouter>
</ErrorBoundary>
```

### Provider Responsibilities

- **ErrorBoundary** — Catch React errors, display fallback UI, log to Sentry (Phase 7+).
- **BrowserRouter** — declarative routing, 3 main routes (/, /pairing, /order-detail/:id).
- **RealtimeProvider** — WebSocket/Reverb connection state, shared event listener registry.
- **QueryProvider** — TanStack Query cache, prefetch strategy, stale-while-revalidate defaults.
- **AuthProvider** — 3-state auth machine (loading → paired/unpaired), localStorage persistence, token verification on boot.
- **I18nProvider** — Language selection context (ja/en/vi), reads from `localStorage`, passed to `useTranslation()` hook.
- **AppProvider** — Theme mode (light/dark), connection badge state, device info context.

## Data Flow

### Read Flow: `/orders` → Dashboard

```
useOrders()
  ↓
TanStack Query cache check (stale-while-revalidate)
  ↓
resolveBaseUrl() picks LAN or cloud
  ↓
apiFetch() injects Bearer token
  ↓
Workstation /api/v1/kds/orders
  OR Cloud /api/v1/kds/orders
  ↓
Parse CustomerOrder[] with items
  ↓
Display on dashboard, cache in QueryProvider
  ↓ (Network error or workstation unreachable)
Fallback to IndexedDB snapshot (Phase 6)
  ↓
Show amber "offline snapshot" banner
```

### Write Flow: Bump Item Status

Kitchen flow: **2 taps per item** — tap 1 = preparing, tap 2 = ready. Topping không cần thao tác riêng.

```
Tap 1: User taps item (pending → preparing)
  ↓
optimistic update (local state → UI)
  ↓
useMarkPreparing() mutation → POST mark-preparing
  ↓
ghi started_preparing_at
  ↓
Workstation broadcasts WS event → other KDS tablets

Tap 2: User taps item again (preparing → ready)
  ↓
optimistic update
  ↓
useMarkReady() mutation → POST mark-ready
  ↓
ghi ready_at (không cần check topping)
  ↓
Workstation broadcasts WS event → Waiter thấy món xong, lấy ra bàn

[Waiter xác nhận] → mark-served (sau ≥ 30s)
```

### Realtime Flow: Event Listener

```
RealtimeProvider connects (LAN or cloud)
  ↓
KDS Client 1 listens: `on("order_item.status_changed", ...)`
KDS Client 2 listens: `on("order_created", ...)`
  ↓
Workstation broadcasts event (LAN) or Cloud Reverb
  ↓
RealtimeProvider forwards to all subscribers
  ↓
Component re-renders (TanStack Query invalidate + refetch)
  ↓
Optimistic update confirmed by server-side event
```

### Boot Verification Flow

```
AuthProvider mounts
  ↓
Read localStorage (kds_device_token + kds_device_info)
  ↓
If absent → state="unpaired" → redirect /pairing
  ↓
If present → call GET /api/v1/kds/me
  ├─ 200 OK → update device info, state="paired"
  ├─ 401 Unauthorized → clear token, state="unpaired", redirect /pairing
  └─ Network error → keep state="paired", use cached info (offline-tolerant)
```

## Backend Dependencies

### Cloud API Endpoints

| Route | Method | Auth | Phase | Purpose |
|-------|--------|------|-------|---------|
| `/api/v1/devices/pair` | POST | — | 3 | Exchange 6-char code for token |
| `/api/v1/kds/me` | GET | Bearer | 3 | Verify token + fetch device info |
| `/api/v1/kds/orders` | GET | Bearer | 4 | List active branch orders |
| `/api/v1/kds/orders/{id}/items/{id}/{mark-preparing\|mark-ready\|mark-served}` | POST | Bearer | 4 | Bump item forward (cloud fallback) |
| `/api/v1/kds/orders/{id}/items/{id}/revert` | POST | Bearer | 4 | Revert item (`{ to }`) |
| `/api/v1/kds/orders/{id}/bump-all` | POST | Bearer | 4 | Bump every item in scope (`{ scope }`) |
| `/api/v1/devices/reverb-config` | GET | Bearer | 5 | Reverb auth token + channel name |
| `/api/v1/devices/broadcasting/auth` | POST | Bearer | 5 | Pusher-protocol channel subscription |

### Workstation Endpoints (LAN Primary)

| Route | Method | Auth | Phase | Purpose |
|-------|--------|------|-------|---------|
| `/api/v1/kds/me` | GET | Bearer | 2 | Verify token on workstation |
| `/api/v1/kds/orders` | GET | Bearer | 2 | List orders from local DB |
| `/api/v1/kds/orders/{id}/items/{id}/{mark-preparing\|mark-ready\|mark-served\|revert}` | POST | Bearer | 2 | Bump with idempotency + WS emit |
| `/ws` | WebSocket | bearer (first-msg) | 5 | Realtime event stream |

### Reverb Channels (Phase 5+)

- `branch.{branch_id}.kds-events` — Workstation broadcasts + Cloud Reverb when cloud fallback active
- Events: `order_item.status_changed`, `order_created`, `order_updated`, `order_paid`

## Routes

godx-kds has 3 main routes:

```
/pairing ──────────────── PairingPage (form to enter 6-char code)
                          ↓ (success)
/ ────────────────────── DashboardPage (kitchen ticket grid, bump UI)
                          ↓ (tap order)
/order-detail/:id ────── OrderDetailPage (full item list + status control)
```

Guards:
- **RequirePaired** — redirects to /pairing if `AuthProvider.state !== "paired"`
- **RequireUnpaired** — redirects to / if already paired

## Cross-References

- **[STACK.md](STACK.md)** — Tech stack rationale (Vite, React, Tailwind, TanStack Query, Laravel Echo, idb, i18n)
- **[AUTH.md](AUTH.md)** — Pairing flow (code exchange), lifecycle states, token storage, global 401 handler
- **[REALTIME.md](REALTIME.md)** — WebSocket protocol, Reverb fallback, RealtimeBackend contract
- **[FAILOVER.md](FAILOVER.md)** — Workstation unreachable detection, 30s backoff, cloud auto-fallback
- **[IDB_CACHE.md](IDB_CACHE.md)** — IndexedDB offline snapshot, sync queue, deferred Phase 6+ implementation notes
- **[HARDWARE_UX.md](HARDWARE_UX.md)** — Wake Lock, auto-lock, CSP constraints, iOS/Android quirks
- Umbrella **plans/plan-027/DESIGN.md** — Full specification (pairing, bump, realtime, offline, phases 0-7)
