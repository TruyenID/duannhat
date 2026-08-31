# web/pos — POS tại quầy

App POS cho quán — React 19 + Vite + TanStack Query + `@godxjp/ui`.

## Dev

```sh
pnpm dev        # → http://localhost:5440
pnpm build      # tsc + vite build
pnpm lint       # eslint
```

## Testing

Vitest + jsdom + Testing Library + MSW.

```sh
npm test                # run once
npm run test:watch      # watch mode
npm run test:coverage   # với coverage report (text + HTML in coverage/)
```

**Coverage Phase 1 targets** (Issue [#282](https://github.com/godx-jp/godx-tempo/issues/282)):
- `src/lib/api.ts` ≥ 80% (current: 87%)
- `src/services/order-*.ts` ≥ 60% (order: 83%, payment: 100%)
- `src/providers/auth-provider.tsx` ≥ 60% (current: 100%)

**Test types:**
- Unit: `src/lib/*.test.ts`, `src/services/*.test.ts`, `src/providers/*.test.{ts,tsx}`
- Integration: `src/__tests__/integration/*.test.ts` (MSW-backed)
- Architecture: `src/app/pos/lib/split-by-items.test.ts` (`@vitest-environment node` for `import.meta.url`)

**Setup notes:**
- Default env: `jsdom`. Override per-file with `// @vitest-environment node` at top.
- `vitest.setup.ts` polyfills `localStorage` (Node 22+ ships an experimental native one that shadows jsdom's).
- Auto cleanup React Testing Library DOM + localStorage + cookies between tests.

## Env vars

```
VITE_API_URL=http://localhost:5400         # Cloud backend
VITE_CONSOLE_URL=https://dev-console.godx.jp  # SSO provider
VITE_SSO_SERVICE_SLUG=tempo
VITE_DEFAULT_LOCALE=vi                     # ja | en | vi
VITE_SHOP_SLUG=sjk                         # Default shop
VITE_WORKSTATION_API_URL=                  # LAN host; empty=localhost:8080, none=disabled
```

## Plans

See `docs/plan/` for active hardening plans.
