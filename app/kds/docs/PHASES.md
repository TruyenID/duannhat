# Implementation Phases

godx-kds implementation followed the phased plan from umbrella plan-027. This document mirrors the phase history for self-contained submodule context — full details in umbrella `plans/plan-027/NOTES.md`.

## Status: COMPLETE — 2026-05-26

All 8 phases (0-7) shipped and integrated. godx-kds is production-ready PWA with offline cache, realtime WebSocket, device pairing, and full kitchen workflow.

## Phase 0 — Cloud Shared Infrastructure

**What**: Cloud backend endpoints for all device types (KDS, Kiosk, TMS). Broadcasting authorization layer for Reverb.

**Owner**: Cloud team (umbrella)

**Summary**: 6 commits shipped. Added neutral device verification, Reverb config, Pusher-protocol signing. Unblocked kiosk WebSocket migration. Backward-compatible with existing kiosk/TMS builds.

**Key endpoints established**:
- `GET /api/v1/devices/me` — any device type verify
- `GET /api/v1/devices/reverb-config` — Reverb setup
- `POST /api/v1/devices/broadcasting/auth` — Pusher channel signing

---

## Phase 1 — Cloud KDS Endpoints

**What**: Cloud REST API for KDS: device identity, orders list, item status bumping.

**Owner**: Cloud team (umbrella)

**Summary**: 10 commits shipped. Added customer_orders DB index, `?updated_since=` cursor, OrderItemStatusChanged event, branch-scoped Reverb channel auth, KDS-typed `/me`, `/orders` list, `/orders/{o}/items/{i}/status` bump. Workstation sync-UP target established.

**Key endpoints**:
- `GET /api/v1/kds/me` — KDS device identity
- `GET /api/v1/kds/orders` — active orders in branch
- `PATCH /api/v1/kds/orders/{o}/items/{i}/status` — bump with idempotency
- `POST /api/v1/devices/broadcasting/auth` — branch.{id}.kds-events channel

**Notable architecture**:
- Idempotency cache namespacing for KDS vs workstation sync
- Service-level event dispatch on every status change
- Backward-compat with legacy `/workstation/orders` recovery flow

---

## Phase 2 — Workstation LAN + Sync

**What**: Workstation (Go + Wails) implements LAN HTTP API + WebSocket, sync UP for KDS bumps, sync DOWN for order recovery.

**Owner**: Workstation team (submodule)

**Branch**: `feature/plan-027-kds-device` (origin/dev)

**Summary**: 9 commits shipped on workstation-app. Added idempotency store, branch-scoped event fan-out, first-message WS auth, local KDS handlers (LAN endpoints), sync UP for bumps (409 revert on conflict), sync DOWN for orders (5s pull tick with `?updated_since=` cursor).

**Key implementation**:
- `POST /api/v1/kds/{me,orders}` — LAN endpoints mirror cloud API
- `PATCH /api/v1/kds/orders/.../status` — idempotent bump + WS broadcast
- `/ws` WebSocket with 5s timeout auth handshake
- `Hub.BroadcastEventScoped(type, payload, branchID)` — branch-scoped filtering
- Sync UP enqueue on bump (409 revert path if cloud rejects)
- Sync DOWN 5s pull tick for order recovery

**Notable architecture**:
- Dual-table situation noted (order_items legacy vs customer_order_items omnify shell) — separate plan
- workstation omnify schema frozen (tracked issue #294)

---

## Phase 3 — Submodule Scaffold + Pairing

**What**: Initialize godx-jp/godx-tempo-kds submodule. Vite + React scaffold, auth infrastructure, pairing form.

**Owner**: Frontend team (godx)

**Branch**: `feature/plan-027-phase-3-frontend` (origin/dev)

**Summary**: 8 commits shipped. Vite 8 + React 19 scaffold, TypeScript strict, Tailwind v4 + OKLCH design tokens, i18n (ja/en/vi), device-token storage + pairing service, base-url resolver (LAN vs cloud), apiFetch wrapper + 401 handler, AuthProvider 3-state machine (loading/paired/unpaired), react-router-dom 7 + route guards.

**Key infrastructure**:
- 18 vitest tests (device-token, base-url-resolver, AuthProvider, router, pairing form)
- Standalone submodule (not pnpm workspace) — own lockfile, inline configs
- dev as default branch (Phase 3+ feature convention)
- AuthProvider boot-time /me verification + offline-tolerant state
- Global 401 handler via apiFetch callback

**What's still scaffolding**:
- Dashboard skeleton (no TanStack Query yet)
- No WebSocket client wired
- No IndexedDB cache

---

## Phase 4 — Dashboard + Bump UI

**What**: Kitchen ticket grid, item status buttons, TanStack Query integration.

**Owner**: Frontend team (godx)

**Branch**: `feature/plan-027-phase-3-frontend` (origin/dev)

**Summary**: 8 commits shipped. TanStack Query setup (QueryProvider, useMe + useOrders hooks prefetch), ticket grid layout (Tailwind responsive), bump UI (status buttons with loading states), optimistic updates, error boundaries, ARIA labels, connection badge placeholder.

**Key features**:
- `useMe()` — device identity + verified on boot
- `useOrders()` — active orders list with stale-while-revalidate
- `useBump()` — PATCH mutation with idempotency key
- Ticket grid (Tailwind responsive 1-4 columns)
- Optimistic UI update → confirmed by WS event or fallback to refetch
- Toast error notifications (sonner)

**Tests**: TanStack Query hook tests (8+), integration tests (kitchen render), edge cases (stale queries, mutation retry)

---

## Phase 5 — Realtime WebSocket + Reverb

**What**: WebSocket realtime events via workstation LAN or cloud Reverb fallback.

**Owner**: Frontend team (godx)

**Branch**: `feature/plan-027-phase-3-frontend` (origin/dev)

**Summary**: 10 commits shipped. RealtimeProvider context, LAN WebSocket client (5s auth handshake, exponential backoff), cloud Echo/Reverb client, dual-backend abstraction (RealtimeBackend contract), event listener registry, connection state tracking.

**Key features**:
- `RealtimeProvider` — single contract, two backends (LAN + cloud)
- `lan-ws.ts` — WebSocket to workstation `/ws`, first-message auth
- `cloud-echo.ts` — Laravel Echo + Pusher.js (fallback)
- `createRealtimeDispatcher()` — picks backend via resolveBaseUrl()
- Event types: `order_item.status_changed`, `order_created`, `order_updated`, `order_paid`
- Exponential backoff (1s, 2s, 4s... 30s max)
- TanStack Query integration: WS event invalidates query cache + refetch

**Tests**: WS protocol tests (auth, reconnect, close codes), backend contract tests

---

## Phase 6 — PWA + IndexedDB + Failover

**What**: Service worker, offline cache (IndexedDB), sync queue for bumps, auto-fallback detection.

**Owner**: Frontend team (godx)

**Branch**: `feature/plan-027-phase-3-frontend` (origin/dev)

**Summary**: 9 commits shipped. vite-plugin-pwa config + manifest, IndexedDB wrapper (idb library), offline snapshot strategy, sync queue (pending bumps), failover detection (30s workstation timeout), connection state badge.

**Key features**:
- vite-plugin-pwa — auto-generates service worker, manifest, offline strategy
- IndexedDB snapshot of orders (cached on successful fetch)
- Offline detection: reads from IDB, queues mutations, shows amber banner
- 30s workstation timeout → auto-fallback to cloud
- Sync queue persists bumps → retry on reconnect
- Manual mode toggle: settings → "Use Cloud" (bypass LAN)
- Hardware UX: Wake Lock (iOS 16.4+), fullscreen PWA, CSP constraints

**Tests**: IDB integration tests, failover detection, sync queue retry logic

---

## Phase 7 — Hardening + Docs Polish

**What**: Final error handling, a11y audit, documentation.

**Owner**: Frontend team (godx)

**Branch**: `feature/plan-027-phase-3-frontend` (origin/dev)

**Summary**: 4 commits shipped (7.1-7.4).

### Task 7.1 — Test Sweep
- Increase vitest coverage to 85%+
- Add missing edge cases: network errors, 401 revocation, 409 conflict reversal
- Audit error paths: hooks + components + services

### Task 7.2 — ErrorBoundary + a11y
- React ErrorBoundary wrapper (outermost, ARCHITECTURE.md nesting)
- ARIA labels on all interactive elements
- Keyboard navigation: Tab through status buttons
- Color contrast audit (Tailwind OKLCH tokens ensure AA)
- Screen reader tests (NVDA, VoiceOver)

### Task 7.3 — README + STACK Polish
- README: Quick start, dev loop, troubleshooting
- STACK.md: Tech rationale (Vite vs Next.js, no Redux, localStorage XSS tradeoff)
- AUTH.md: Pairing flow, lifecycle states, 401 handler, token revocation
- Cross-reference links between docs

### Task 7.4 — Final Docs (THIS TASK)
- **ARCHITECTURE.md**: System topology, provider nesting, data flow, API dependencies, routing
- **FLOW_DIAGRAMS.md**: ASCII diagrams for 8 key flows (pairing, bump LAN, bump cloud, reconnect, failover, token revoke, offline boot, connection state)
- **PHASES.md**: This document — phase summary for submodule context
- **INTEGRATION_GAPS.md**: Known issues, deferred work, tech debt (sound file placeholder, icon placeholders, dual-table, workstation omnify, offline queue deferred, ConnectionBadge polling inefficiency)
- Umbrella **docs/guide/setup-kds-device.md**: Support team how-to (device creation, pairing, PWA install, troubleshooting, hardware checklist)

---

## See Also

- Umbrella **plans/plan-027/NOTES.md** — Full phase details with commits
- Umbrella **CLAUDE.md** — Project overview, architecture diagram, omnify codegen
- This app's **CLAUDE.md** — Quick links, tech stack, conventions
- **STACK.md** — Tech rationale
- **AUTH.md** — Authentication lifecycle
- **REALTIME.md** — WebSocket protocol
- **FAILOVER.md** — Failover behavior
- **IDB_CACHE.md** — IndexedDB cache strategy
- **HARDWARE_UX.md** — iOS/Android constraints
