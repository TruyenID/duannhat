# godx-kds

TempoFast Kitchen Display System (KDS) — tablet bếp (kitchen) app. PWA built with Vite + React 19. Plan-027 shipped; plan-028 in progress (gen-2 KDS API consumer migration).

## Quick Links

- [README.md](README.md) — Quick start, architecture overview.
- [docs/STACK.md](docs/STACK.md) — Tech stack rationale.
- [docs/AUTH.md](docs/AUTH.md) — Device pairing flow + auth lifecycle.
- [docs/REALTIME.md](docs/REALTIME.md) — Echo/WS dispatcher + self-echo dedup contract.
- [docs/FAILOVER.md](docs/FAILOVER.md), [docs/IDB_CACHE.md](docs/IDB_CACHE.md), [docs/PHASES.md](docs/PHASES.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
- Umbrella `plans/plan-027/` — KDS phase-3 to phase-7 design.
- Umbrella `plans/plan-028/` — gen-2 API thickening (in progress).

## Status

Plan-027 phases 1-7 shipped (pairing, dashboard, TanStack Query, realtime via Echo/LAN, PWA + idb snapshot, theme/audio/wake-lock, a11y/ErrorBoundary). Default branch `dev`.

**Plan-028 Phase 6 (current)**: Migrating gen-1 PATCH `/orders/:id/items/:id/status` → gen-2 operation endpoints + server-derived fields + RFC 7807 error UX.

## Tech Stack

- **Build**: Vite 8, TypeScript 6 (strict + erasableSyntaxOnly), vite-plugin-pwa.
- **Framework**: React 19, react-router-dom 7 (2 routes: `/`, `/pairing`).
- **UI**: Tailwind CSS v4, `@godxjp/ui` (Radix + Tailwind), lucide-react.
  - Toast: `<KdsToaster>` from `src/components/kds-toaster.tsx` wraps `sonner` directly (NOT `@godxjp/ui`'s Toaster) so theme follows `useTheme()`. `@godxjp/ui`'s Toaster hardcodes `theme: "light"` and ignores dark mode — open upstream PR when adopting in web/pos or web/admin.
- **Data**: TanStack Query v5, idb (IndexedDB offline snapshot).
- **Realtime**: Laravel Echo + Pusher.js (LAN WS via workstation, fallback cloud Reverb). Self-echo dedup via `recordBumpKey()` (30s TTL).
- **i18n**: Custom `useTranslation()` hook reading flat-dot-key JSON (`ja/en/vi.json`, ja default).
- **Testing**: vitest 4, @testing-library/react, jsdom, fake-indexeddb.

## Run

```sh
pnpm install
pnpm dev        # http://localhost:5460
pnpm build      # production bundle → dist/
pnpm typecheck  # TypeScript strict check
pnpm lint       # ESLint + Prettier
pnpm test       # vitest run
```

## Architecture

**LAN-first PWA**: Tablet opens cloud-hosted kds-web, then connects to workstation LAN (mDNS discovery) for orders + bumps. Falls back to cloud direct if workstation unreachable (30s backoff, then retry). WebSocket via workstation gateway (LAN) or cloud Reverb (fallback).

**Auth**: Device pairing via 6-char code → cloud `/api/v1/devices/pair` → `localStorage` token + device info. Heartbeat `/api/v1/kds/me` on boot to verify token. Global 401 handler clears token on revocation.

**API Routes** (all through `resolveBaseUrl()`, workstation LAN primary, cloud fallback):

| Endpoint | Method | Auth | Generation | Description |
|----------|--------|------|------------|-------------|
| `/api/v1/devices/pair` | POST | Public | — | 6-char code → device token |
| `/api/v1/kds/me` | GET | Bearer | — | Verify token, fetch device info (KdsDeviceResource) |
| `/api/v1/kds/orders` | GET | Bearer | — | List active orders (KdsOrderResource[] + meta) |
| `/api/v1/kds/orders/:o/items/:i/mark-preparing` | POST | Bearer | gen-2 | Advance pending→preparing |
| `/api/v1/kds/orders/:o/items/:i/mark-ready` | POST | Bearer | gen-2 | Advance preparing→ready |
| `/api/v1/kds/orders/:o/items/:i/mark-served` | POST | Bearer | gen-2 | Advance ready→served (30s anti-misclick) |
| `/api/v1/kds/orders/:o/items/:i/revert` | POST | Bearer | gen-2 | Body `{to: pending\|preparing}` |
| `/api/v1/kds/orders/:o/bump-all` | POST | Bearer | gen-2 | Body `{scope: pending\|preparing}` bulk advance |
| `/api/v1/kds/orders/:o/items/:i/status` | PATCH | Bearer | gen-1 deprecated | Sunset 2026-07-12; redispatches to gen-2 |

**Idempotency**: All gen-2 POST require `Idempotency-Key` header (UUID). Bump-all cloud derives per-item key as `${batchKey}:${itemId}` and broadcasts that key per OrderItemStatusChanged event — `useBumpAll` MUST pre-record all derived keys for self-echo dedup to work.

**RFC 7807 errors**: gen-2 endpoints return `{type, title, status, code, detail, context, remediation}` with codes `KDS_E001`–`KDS_E008`. Workstation LAN mirror returns simpler `{message}` envelope (by DESIGN.md §6) — FE error parser handles both.

**Bump failures are never queued for later sync.** IndexedDB is a read-side
order snapshot only; it is not an offline mutation queue. When a single-item
bump, revert, or bump-all request fails, the mutation must roll its optimistic
cache update back and surface the error toast so the cook can retry immediately.
Do not “improve” this path by enqueueing or replaying a failed bump: a bump that
syncs an hour later would move a ticket after the food is already cold. Changing
this contract requires an explicit product decision, not a passing resiliency
refactor.

## Key Conventions

- **NOT in pnpm workspace** — `pnpm-workspace.yaml` lists only `packages/*`. Own `pnpm-lock.yaml`, own ESLint/tsconfig/Prettier configs (NOT `@tempo/` workspace imports). Sync from pos-web quarterly.
- **Components colocated** — Page-level UI under `src/app/<route>/components/`. Cross-cutting under `src/components/`.
- **Hooks**: TanStack Query mutations under `src/hooks/use-*.ts`. Tests under `src/hooks/__tests__/`.
- **API layer** — `src/lib/api.ts` (`apiFetch()` helper parsing RFC 7807 into `ApiError`), `src/services/` (handwritten).
  - No raw `fetch` outside API layer (ESLint rule: `no-restricted-globals`).
  - Exception: `src/services/auth/pairing.ts` (raw fetch for code exchange, no token yet).
- **Type fidelity** — `KdsItem`, `KdsOrder`, `KdsItemTopping`, `KdsOrderTable` in `src/services/kds/orders.ts` mirror cloud `KdsItemResource`/`KdsOrderResource` exactly. Test fixtures: `src/test/fixtures/kds.ts` (`makeOrder()`/`makeItem()`).
- **localStorage only** — Device token + info, language preference, theme. XSS acceptable: kitchen device, no untrusted content, strict CSP.
- **NO Co-Authored-By in commits** — Umbrella convention.

## Folder Structure

```
app/kds/
├── src/
│   ├── main.tsx                    (createRoot → <App />)
│   ├── App.tsx                     (provider tree + routes + Toaster)
│   ├── app/
│   │   ├── pairing/
│   │   │   ├── page.tsx            (/pairing — 6-char form)
│   │   │   └── components/
│   │   └── dashboard/
│   │       ├── page.tsx            (/ — order grid)
│   │       └── components/
│   │           ├── ticket-card.tsx
│   │           ├── ticket-grid.tsx
│   │           ├── item-row.tsx
│   │           ├── status-badge.tsx
│   │           ├── connection-badge.tsx
│   │           ├── settings-drawer.tsx
│   │           └── __tests__/
│   ├── components/
│   │   ├── error-boundary.tsx
│   │   └── error-fallback.tsx
│   ├── providers/
│   │   ├── AppProvider.tsx         (theme + audio)
│   │   ├── AuthProvider.tsx
│   │   ├── QueryProvider.tsx
│   │   ├── RealtimeProvider.tsx    (Echo + self-echo dedup)
│   │   └── WakeLockProvider.tsx
│   ├── hooks/
│   │   ├── use-me.ts
│   │   ├── use-orders.ts
│   │   ├── use-mark-preparing.ts   (plan-028)
│   │   ├── use-mark-ready.ts       (plan-028)
│   │   ├── use-mark-served.ts     (plan-028)
│   │   ├── use-bump-all.ts        (plan-028, records N derived keys)
│   │   ├── use-revert-item.ts     (plan-028)
│   │   ├── use-bump.ts            (gen-1, deprecated — remove after migration)
│   │   ├── use-audio-chime.ts
│   │   └── __tests__/
│   ├── lib/
│   │   ├── api.ts                  (apiFetch, ApiError, RFC 7807 parser)
│   │   ├── idb.ts                  (offline snapshot)
│   │   └── utils.ts                (cn, ageInMinutes, ageColorClass)
│   ├── services/
│   │   ├── auth/
│   │   │   ├── pairing.ts          (raw fetch for code exchange)
│   │   │   └── device-token.ts     (localStorage persist)
│   │   ├── base-url-resolver.ts    (LAN primary + cloud fallback)
│   │   ├── kds/
│   │   │   ├── me.ts
│   │   │   ├── orders.ts           (KdsOrder/KdsItem types + getOrders)
│   │   │   ├── operations.ts       (gen-2 markPreparing/Ready/Served/revert/bumpAll)
│   │   │   ├── bump.ts             (gen-1, deprecated)
│   │   │   └── __tests__/
│   │   └── realtime/
│   ├── test/
│   │   └── fixtures/
│   │       └── kds.ts              (makeOrder/makeItem builders)
│   ├── i18n/
│   │   ├── ja.json                 (source of truth)
│   │   ├── en.json
│   │   ├── vi.json
│   │   ├── index.tsx
│   │   └── useTranslation.ts
│   └── styles/
├── docs/                            (STACK, AUTH, REALTIME, FAILOVER, IDB_CACHE, HARDWARE_UX, PHASES, ARCHITECTURE, FLOW_DIAGRAMS, INTEGRATION_GAPS)
├── public/                          (PWA icons, manifest)
├── eslint.config.js
├── tsconfig.json                    (strict, erasableSyntaxOnly)
├── vite.config.ts
├── vitest.config.ts
└── package.json
```

## ESLint Rules (Inline)

```javascript
// eslint.config.js
{
  rules: {
    "no-restricted-globals": [
      "error",
      { name: "fetch", message: "Use apiFetch() from lib/api.ts" }
    ]
  },
  overrides: [
    {
      files: ["src/lib/api.ts", "src/services/auth/pairing.ts"],
      rules: { "no-restricted-globals": "off" }
    }
  ]
}
```

Reason: Pairing has no token (raw fetch needed). All other calls use `apiFetch()` with token injection + 401 handling + RFC 7807 parsing.

## Testing

- **Hook tests**: `src/hooks/__tests__/use-*.test.tsx`. Wrap with `AuthProvider` + `QueryClientProvider` + `RealtimeProvider` (mock dispatcher to avoid real WS).
- **Component tests**: `src/app/<route>/components/__tests__/*.test.tsx`. Add `I18nProvider` outermost.
- **Fixtures**: Import from `@/test/fixtures/kds` (`makeOrder()`, `makeItem()`, `makeTable()`, `makeTopping()`) — fills all derived fields with sensible defaults. Override only the fields the test cares about.
- **API mock**: `global.fetch = vi.fn().mockImplementation(...)` per test. No MSW.
- **Realtime mock**: `vi.mock("@/services/realtime/dispatcher", () => ({ createRealtimeDispatcher: vi.fn(() => ({ on: vi.fn(() => () => {}), connect: vi.fn(), close: vi.fn() })) }))`.

## Debugging

```sh
pnpm dev
# Open http://localhost:5460
# Check DevTools Console, Network, Storage (localStorage)
```

**localStorage inspection** (DevTools Application tab):
```
kds_device_token: "eyJ..." (or empty if unpaired)
kds_device_info: {"id": "...", "name": "...", ...}
kds_language: "ja" | "en" | "vi"
kds_api_mode: "auto" | "workstation" | "cloud"
```

**Network**: Filter by `/api/v1/kds/*`. Watch for 401 (revocation), 409 with `code: "KDS_E0xx"` (RFC 7807 catalog).

## Common Tasks

### Add a new page
1. Create `src/app/<route-name>/page.tsx`.
2. Add route entry in `App.tsx` `<Routes>`.
3. Create colocated components under `src/app/<route-name>/components/`.
4. Use `apiFetch()` or handwritten service in `src/services/`.

### Add a new gen-2 KDS operation
1. Add service function in `src/services/kds/operations.ts` (POST with `Idempotency-Key` header).
2. Add hook in `src/hooks/use-<op>.ts` (TanStack Query mutation, optimistic + rollback, `recordBumpKey` for self-echo).
3. Add test in `src/hooks/__tests__/use-<op>.test.tsx` (cover happy + error rollback).
4. Wire in component (read `allowed_transitions` from API for button visibility).

### Update i18n strings
1. Add key to `src/i18n/ja.json` (source of truth).
2. Add translations to `en.json`, `vi.json`.
3. In component: `const { t } = useTranslation(); t("key.path")`.

### Pair with workstation
1. Boot KDS, redirect to `/pairing`.
2. Enter code (Admin created in admin-web).
3. KDS POSTs cloud `/api/v1/devices/pair`.
4. Token + device info → localStorage.
5. Redirect to `/` (kitchen order list).
6. On subsequent requests, `resolveBaseUrl()` tries workstation mDNS first → cloud fallback.

## See Also

- Umbrella `plans/plan-027/` + `plans/plan-028/` — phase specs.
- Umbrella `CLAUDE.md` — Project list, architecture diagram, omnify codegen.
- `web/pos/CLAUDE.md` — Shared style guide (Vite, React, Tailwind setup).
