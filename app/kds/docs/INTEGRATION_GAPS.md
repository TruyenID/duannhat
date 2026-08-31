# Integration Gaps

Known issues, deferred work, and technical debt in godx-kds. Last full sweep 2026-05-28 covering Sprint A→C security hardening + Sentry rollout.

## ✅ Resolved (2026-05-29)

| Gap | Resolved by | Notes |
|-----|-------------|-------|
| `assertToppingsParentReady` block kitchen khi topping còn `pending` — nhưng topping không có endpoint riêng để bump, nên bếp bị kẹt | Removed guard từ `KdsBusinessRules` + `KdsController::markReady` | Topping chỉ là ghi chú thành phần, không cần track chế biến riêng. Flow kitchen: tap 1 = preparing, tap 2 = ready — không cần thao tác topping. |
| Kitchen không thấy topping trên ticket | Added topping list vào `ItemRow` (tên + quantity, không có status badge) | Topping hiển thị dạng text để bếp biết thành phần món, không block flow |

## ✅ Sprint A→C resolved (2026-05-28)

| Gap | Resolved by | Notes |
|-----|-------------|-------|
| No CSP backing the "strict CSP" promise CLAUDE.md uses to justify `localStorage` device_token | Sprint B.5 `06ee815` + fixup `e90a3b3` | CSP meta in `index.html`: default-src 'self', script-src 'self', worker-src/manifest-src for PWA Workbox, Referrer-Policy strict-origin, Permissions-Policy deny-all sensors |
| `tsconfig.app.json` silently lacked `strict: true` despite CLAUDE.md + STACK.md claiming "strict + erasableSyntaxOnly" | Sprint B.6 `7c83acf` | `strict: true` enabled; zero new errors (code was strict-conformant by accident); `STACK.md:96` updated to note `noUncheckedIndexedAccess` still NOT enabled (tracked) |
| No error tracking — uncaught errors only console.error'd | Sprint C.1 `6cd7f5b` + fixup `b713d5b` | `@sentry/react` wired via `src/lib/sentry.ts`; ErrorBoundary routes `componentDidCatch` to `captureException` with global boundary tag; `beforeBreadcrumb` + `beforeSend` redact `kds_device_token` (both quoted + unquoted forms) + Bearer tokens from messages, exception values, and componentStack |
| No `.env.example` for Sentry/Cloud URL vars | Sprint D `a8acf04` | Template with `VITE_SENTRY_DSN` + `VITE_RELEASE` + `VITE_CLOUD_URL` + `VITE_DEFAULT_LOCALE` |

## Open Sentry follow-ups

- **Source-map upload**: prod bundle is minified; without `@sentry/vite-plugin` + CI-set auth token, stack traces in Sentry are unreadable. Add the plugin + provision SENTRY_AUTH_TOKEN in CI before flipping `VITE_SENTRY_DSN` on prod.
- **Sentry alert pipeline**: no Sentry alert rule wired for ErrorBoundary captures yet. After Sentry project is provisioned, configure routing (e.g. `boundary:global` tag → Slack #kds-ops).
- **`noUncheckedIndexedAccess`**: still off. Would flood Array/Map access sites; opportunistic cleanup is the realistic path.
- **CSP `connect-src` too loose**: currently `http: https: ws: wss:` because mDNS-discovered LAN IPs differ per restaurant. Tightening to RFC1918 wildcards is feasible but verbose. Tracked.
- **CloudEchoClient** only subscribes `.order_item.status_changed`. Adding `order_created` / `order_updated` / `order_paid` subscriptions is blocked by backend not broadcasting them yet — P2 tech debt, not a current bug.

## Placeholder Assets

### Audio: `public/sounds/new-order.mp3`

**Status**: Placeholder file (silent 1-second MP3).

**Issue**: Kitchen audio chime needs a real sound file. Current placeholder satisfies browser autoplay policy (requires user gesture → test button) but provides no user-perceptible feedback.

**Deferred to**: Design/UX team to provide CC0 or licensed audio file.

**Notes**:
- Test button in settings (Phase 7.2) required for audio playback to work on iOS.
- Fallback: visual notification (toast) if audio fails — already implemented.

### Icons: `public/icons/icon-{192,512}.png`

**Status**: Placeholder generated PNG files.

**Issue**: PWA manifest references these for home screen install. Current placeholders are generic (solid color background, no artwork).

**Deferred to**: Designer to provide proper KDS branding (192px + 512px).

**Notes**:
- vite-plugin-pwa already configured to preprocess and validate icon sizes.
- No code changes needed — designer drops files and PWA auto-updates.

## Workstation Tech Debt

### Dual-Table Situation: `order_items` vs `customer_order_items`

**Status**: Unresolved, acknowledged in Phase 2.

**Issue**: Workstation post-Sprint-4 introduced legacy `order_items` table (status, served_at, voided_at, customer_order_id FK). Omnify schema has `customer_order_items` (omnify shell, empty). KDS and cloud use `customer_order_items` name in schema, but workstation-app queries `order_items` (legacy).

**Current workaround** (Phase 2):
- Cloud API returns `customer_order_items` response shape (omnify aligned).
- Workstation `/api/v1/kds/orders` queries local `order_items` table (legacy).
- KDS consumes both without issue (field names match).

**Deferred to**: Separate plan to:
1. Refactor workstation `order_service.go` to use `customer_order_items`.
2. Migrate or deprecate `order_items` table.
3. Sync omnify `schemas/Workstation/` codegen.

**Risk**: Cloud schema evolution (new fields on `customer_order_items`) may drift from workstation's hardcoded `order_items` query. Low risk currently (stable schema post-Sprint-4).

**Tracked**: GitHub issue [#294](https://github.com/godx-jp/godx-tempo/issues/294) (omnify v38 codegen behavior).

### Workstation Omnify Schema Frozen

**Status**: `schemas/Workstation/` is empty.

**Issue**: Workstation omnify regen only generates enums. Object tables frozen at 2026-04 state. Cloud schema changes after that date may not propagate to workstation definitions.

**Current impact**:
- KDS unaffected (uses hand-maintained order_items queries).
- POS/Kiosk unaffected (use cloud API directly).
- Future features: if cloud adds new fields to `customer_orders` or `customer_order_items`, workstation TypeScript types won't auto-update.

**Deferred to**: Workstation omnify regen plan (requires investigation of why Workstation schema is empty in Omnify YAML).

**Workaround**: Hand-edit types in `workstation/frontend/src/` as needed.

## Phase 6+ Deferred Features

### Offline Sync Queue for Bumps

**Status**: Deferred, architecture reserved.

**Issue**: Current Phase 6 offline support:
- Reads from IndexedDB snapshot (orders cached on successful fetch).
- Shows amber "offline snapshot" banner.
- Staff can tap items (updates local state) — but mutations are **not queued**.

**Deferred behavior** (Phase 7+):
- On offline bump: persist mutation to `sync_queue` table in IndexedDB.
- On reconnect: replay queue mutations against server (idempotency keys prevent duplicates).
- Visual feedback: show "syncing..." badge on queued bumps until server confirms.

**Reason deferred**: Phase 6 scope prioritized read-side offline (snapshot visibility). Write-side sync queue requires:
1. IDB transaction safety (queue durability).
2. Replay logic (detect already-applied mutations via idempotency).
3. UI polish (show which bumps are pending sync).
4. Testing (error recovery, partial queue, network flakiness).

**No code debt**: IDB schema is ready for `sync_queue` table. Just needs consumer code.

**Tracked**: Comment in `src/lib/idb.ts` marks the column definition.

### ConnectionBadge Polling Inefficiency

**Status**: Works correctly, but not optimal.

**Issue**: `ConnectionBadge` component polls `resolveBaseUrl().via` every 3 seconds (hardcoded timer in `useEffect`).

**Current behavior**:
- Correctly shows "Connection: LAN" or "Connection: Cloud"
- Updates after 30s workstation timeout
- Not responsive to user toggle in settings (lag up to 3s)

**Better approach** (Phase 7+):
- Export `useConnectionState()` hook from `RealtimeProvider`
- Badge subscribes to event-driven updates (no polling)
- Settings mode toggle emits `useContext` state change → badge updates immediately

**Why deferred**: Requires refactoring RealtimeProvider to expose state as Context hook. Low priority (current polling UX is acceptable for kitchen environment).

**Tracked**: TODO comment in `src/app/(home)/components/ConnectionBadge.tsx`.

## Phase 5 Notes: WebSocket Stability

### LAN WebSocket Close Code 4401 (Bad Token)

**Status**: Works as designed.

**Note**: If server closes with 4401, client **does NOT auto-reconnect**. Token is revoked, staff must re-pair.

**Potential UX gap**: No explicit error message to staff. Current behavior is silent redirect to /pairing (via global 401 handler on next API call).

**Improvement** (Phase 7+): Show toast "Device unpaired" + reason on 4401, then redirect.

### Reverb Channel Naming

**Status**: Works correctly, potential optimization.

**Issue**: Cloud Reverb channel is `branch.{branch_id}.kds-events` (30+ char string for UUID). Large message overhead on many simultaneous tablets.

**Alternative**: Use numeric branch lookup ID instead of UUID. Deferred (low impact, Reverb handles it fine).

## Known Limitations

### iOS Audio Autoplay

**Status**: By design (browser security policy).

**Issue**: Audio chime won't auto-play on browser load. Requires user gesture (tap or navigation).

**Workaround**: Phase 7.2 added "Test Sound" button in settings. Staff taps once, audio autoplay is unlocked for session.

**Alternative**: Use haptic feedback (iOS 13+) as fallback.

### Android PWA App Icon

**Status**: Works, minor UX gap.

**Issue**: Android home screen shortcut doesn't auto-update if `manifest.json` changes.

**Workaround**: Staff re-installs PWA (remove + re-add from home screen).

### CSP Constraints

**Status**: Strict CSP in `index.html`.

**Constraints**:
- No inline `<script>` (only external bundled scripts)
- No `unsafe-eval` (prevents eval-based libraries)
- No third-party resource CDN except Google Fonts + Pusher.js

**Reason**: Kitchen device, no untrusted content, XSS risk minimal. Strict CSP prevents accidental dependency bloat.

**Impact**: Some libraries (analytics, error tracking) may not work. Current setup uses no external tracking (local logging only).

## GitHub Issues

Track these for future phases:

- [#294](https://github.com/godx-jp/godx-tempo/issues/294) — Omnify v38 codegen behavior (workstation schema frozen)
- Related: Tech debt inventory in umbrella `project_workstation_tech_debt.md`

## See Also

- **PHASES.md** — Full phase history with shipped commits
- **ARCHITECTURE.md** — System design (no known architectural gaps)
- **STACK.md** — Tech stack rationale (all choices validated)
- Umbrella **plans/plan-027/NOTES.md** — Phase-by-phase notes and verifications
- Umbrella **docs/guide/setup-kds-device.md** — Support team how-to (references this doc for troubleshooting)
