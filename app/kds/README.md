# app/kds — Kitchen Display System

Tablet bếp của TempoFast. Displays orders in realtime, staff bump items through prep workflow. Progressive Web App (Vite + React 19).

## Quick Start

```sh
pnpm install
pnpm dev        # http://localhost:5460
pnpm build      # production bundle
pnpm typecheck  # TypeScript strict check
pnpm lint       # ESLint + Prettier
pnpm test       # vitest + @testing-library/react
```

## Architecture

**LAN-first PWA** — tablet runs cloud-hosted bundle, but connects to workstation (mDNS discovery) for orders + bumps. Falls back to cloud direct if workstation unreachable (30s auto-failover). WebSocket realtime via workstation Reverb gateway or cloud Pusher.js.

- **Pairing**: Admin creates device in admin-web → 6-char code → staff enters code → KDS exchanges code for device token (cloud direct, no token yet).
- **Device info**: Cached in `localStorage` after pair — device id, name, branch_id, branch_name.
- **Auth state**: `loading` → `paired` (token valid) or `unpaired` (no token or 401).
- **Network**: `/me` heartbeat + `/orders` query route through `resolveBaseUrl()` (workstation LAN primary).

See [AUTH.md](docs/AUTH.md) for full pairing + lifecycle flow.

## Tech Stack

See [STACK.md](docs/STACK.md) for rationale + dependency list.

Quick summary:
- **Build**: Vite 8, TypeScript 6, vite-plugin-pwa
- **UI**: React 19, react-router-dom 7, Tailwind CSS v4, @godxjp/ui, lucide-react, sonner (toast)
- **Data**: TanStack Query v5, idb (IndexedDB cache)
- **Realtime**: Laravel Echo + Pusher.js (WebSocket, Phase 5+)
- **i18n**: Custom `useTranslation()` hook (ja/en/vi, ja default)

## Key Conventions

- **NOT a pnpm workspace member** — Own `pnpm-lock.yaml`, own ESLint/tsconfig/Prettier configs (synced from pos-web quarterly).
- **Components colocated** — all under `src/app/<route>/components/`. No domain-bucket top-level structure.
- **API layer**: `src/lib/api.ts` + `src/services/` (handwritten or omnify-generated).
- **No raw fetch** — `apiFetch()` helper required (except pairing @ `src/services/auth/pairing.ts` which has no token yet).
- **localStorage only** — device token + info stored client-side (XSS acceptable: kitchen device, no untrusted content, strict CSP).


## Status

Plan-027 Phase 3. Core routes, auth, order list working. Phase 4: TanStack Query prefetch. Phase 5: WebSocket realtime. Phase 6: offline idb cache.

See `/plans/plan-027/` in umbrella root for full design + roadmap.
