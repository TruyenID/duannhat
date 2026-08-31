# godx-kds

TempoFast Kitchen Display System (Vite + React PWA).

## Status

Plan-027 Phase 3 scaffold. See umbrella `plans/plan-027/` for full design.

## Tech Stack

Vite 8, React 19, TypeScript, react-router-dom 7, TanStack Query v5, Tailwind CSS v4, sonner, Laravel Echo + Pusher.js, idb, vite-plugin-pwa.

## Run

```sh
pnpm dev      # http://localhost:5460
pnpm build
pnpm typecheck
pnpm lint
```

## Architecture (Plan-027)

LAN-first KDS PWA. Tablet bếp opens cloud-hosted bundle, runtime-resolves base URL between workstation LAN and cloud direct. PATCH item bumps via workstation LAN (primary) with cloud fallback when workstation unreachable. WebSocket realtime via workstation (LAN) or Reverb (cloud).

## Key conventions

- Standalone submodule (NOT a pnpm workspace member). Own lockfile, own configs (ESLint + tsconfig + Prettier inline — not workspace refs).
- NO `Co-Authored-By: Claude` line in any commit.
- Components 100% colocated under `src/app/<route>/components/`. No top-level domain buckets.
- Style guide + ESLint rules copied from pos-web; sync manually quarterly.
