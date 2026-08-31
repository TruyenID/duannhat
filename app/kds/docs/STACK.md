# Stack

Tech choices, rationale, and constraints for KDS.

## Build + Dev Tooling

- **Vite 8** — Fast bundler + dev server (ESM-native). Port 5460.
- **TypeScript 6** — Strict mode, `erasableSyntaxOnly: true` (no parameter-property shorthand).
- **vitest 4** — Unit + component tests, ESM-compatible, `jsdom` environment.
- **@testing-library/react** — Component testing (accessible queries, user-centric assertions).
- **vite-plugin-pwa** — Service worker auto-generation, manifest.json, offline support (Phase 6).

## Framework + Routing

- **React 19** — RSC-aware but using client-only mode (PWA has no server rendering).
- **react-router-dom 7** — Declarative routing (`<BrowserRouter>`, `<Routes>`, `useNavigate`). Simpler than TanStack Router for 3-route app.
- **TanStack Query v5** — Server state caching (prefetch, stale-while-revalidate). Phase 4+.

## Styling + UI

- **Tailwind CSS v4** — `@tailwindcss/vite` plugin, OKLCH design tokens (SmartHR design system).
- **@godxjp/ui** — Shared component library (Radix primitives + Tailwind, GitHub-hosted SSH). Buttons, modals, forms, layouts.
- **lucide-react** — Icon library (24px default, tuned for kitchen UX).
- **sonner** — Toast notifications (lightweight, type-safe, async Promise support).
- **M PLUS 2 font** — Japanese enterprise design system. Loaded via `@fontsource-variable/m-plus-2` or Google Fonts in `index.html`.

## Realtime + Data

- **Laravel Echo** — WebSocket client for cloud Reverb broadcaster (same as admin-web, tms-app, workstation-app).
- **Pusher.js** — Fallback for direct cloud connection when workstation unreachable.
- **idb** — IndexedDB wrapper for offline order cache (Phase 6: sync up on reconnect).

## i18n

Custom lightweight `useTranslation()` hook. No react-intl bloat. Three languages:
- `ja` — Default, full translations
- `en` — English fallback
- `vi` — Vietnamese (staffing in VN shops)

Key-value YAML files under `src/i18n/{ja,en,vi}.yaml`. Hook reads selected lang from `localStorage` + useContext(AuthProvider).

## Why These Choices

### Vite over Next.js
KDS is client-side-only PWA — no SSR needed. Vite's dev loop is faster (HMR sub-100ms). Matches pos-web precedent (also Vite + React). Next.js adds complexity (API routes, middleware) with no benefit.

### react-router-dom over TanStack Router
Matches pos-web team experience. Router is small (3 routes: `/`, `/pairing`, `/order-detail/:id`). TanStack Router's advanced features (loaders, deferred data) unnecessary here. Keep it simple.

### No state library (Redux/Zustand/Recoil)
- TanStack Query handles server state (orders, items, devices).
- React Context handles client state (auth token, device info, theme, i18n lang).
- KDS state is small + shallow. Context re-render acceptable.

### Manual i18n over react-intl
- `react-intl` is 45KB (minified), pulls in `intl` standard library.
- Custom hook is <500 bytes. KDS has ~30 translation keys total.
- YAML is cleaner than JSON nested objects.

### Laravel Echo + Pusher.js
- Cloud uses Reverb broadcaster (Pusher-protocol compatible).
- Workstation proxies via WebSocket gateway (Phase 5).
- Consistent with admin-web, tms-app, workstation-app stack.
- Fallback to HTTP polling if WebSocket fails.

### localStorage for auth token
- No HttpOnly cookie option — backend uses Bearer token pattern (consistent across kiosk, tms, workstation).
- Switching to cookies requires backend auth refactor + CORS preflight complexity.
- XSS risk acceptable: KDS is kitchen device, renders no untrusted content, strict CSP in `index.html`.
- localStorage keys: `kds_device_token`, `kds_device_info` (JSON), `kds_language` (ja/en/vi).

## Constraints

### Standalone (not a pnpm workspace member)
- NOT a pnpm workspace member — has own `pnpm-lock.yaml`.
- ESLint, tsconfig, Prettier configs inlined (not `@tempo/` workspace imports).
- Sync from pos-web quarterly (copy-paste style updates, ESLint rule changes).

### API Layer
- `src/lib/api.ts` — `apiFetch()` helper wraps fetch, injects Bearer token, handles 401 redirect to unpair.
- `src/services/auth/pairing.ts` — Raw fetch allowed (pairing has no token yet, can't use `apiFetch`).
- All other API calls must use `apiFetch()` or omnify-generated service hooks.

### Component Organization
- All components colocated under `src/app/<route>/components/`.
- No domain buckets (e.g., no `src/components/orders/`, `src/components/items/`).
- Shared utilities under `src/lib/`, services under `src/services/`.

### ESLint Rules
- `no-restricted-globals`: blocks raw `fetch` outside API layer.
  - Carve-out: `src/lib/api.ts`, `src/services/auth/pairing.ts`
- Enforce `@godxjp/ui` imports (no inline Radix).
- No default exports from services (named exports only).

### TypeScript
- Strict mode: `"strict": true` in `tsconfig.app.json` — covers `strictNullChecks`, `noImplicitAny`, `strictFunctionTypes`, etc.
- `noUncheckedIndexedAccess` is NOT enabled yet (was claimed here previously but the flag isn't on); enabling it would flood the existing array/Map access sites — tracked as a follow-up.
- No parameter-property shorthand (`erasableSyntaxOnly: true`).
- All function params typed.

## Dependencies Version Policy

- **Vite, React, TypeScript**: Follow pos-web (v8, v19, v6).
- **@godxjp/ui**: Always `github:godx-jp/godx-tempo-ui#main` (latest commit).
- **TanStack Query**: Match admin-web (v5).
- **Tailwind CSS**: Match umbrella (v4).
- **Other libraries**: Pin to minor version (`^X.Y.0`) to avoid breaking changes between PR reviews.

Update once per quarter alongside pos-web sync (planned + tested together).
