# Workstation serves pos-web at `/pos` (same-origin LAN)

**Status:** shipped (#1169). Tracks plan-052 T3.5 / T3.6 / T3.7.

## The problem this solves

A shop with more than one machine runs pos-web on a **tablet** that is *not* the
workstation PC. Loaded from the Amplify domain, pos-web is served over **HTTPS**,
but the workstation only speaks plain **`http://<lan-ip>`**. Browsers block that
combination as **mixed content** — so the print buttons and every other LAN
feature silently never worked on a multi-machine shop, and there was no
production workaround (tunnels were dev-only; ws-app ships no `cloudflared`).

The fix: the **workstation is the local hub**. It serves the pos-web app itself
at **`/pos`**, from the same origin as its LAN API. A tablet only needs **one
address** — `http://<ws-ip>:<port>/pos` — and every API/print call it makes is
same-origin `http`. Mixed content disappears at the root, and the shop keeps
working with the Internet unplugged.

The Amplify (cloud) delivery still exists in parallel — same codebase, two
distribution modes. A cloud-only shop uses Amplify; a shop with a workstation
uses the local hub.

## How it works

One pos-web codebase, two build modes (only the base path differs; behaviour
lives in the resolver, which reads `import.meta.env.BASE_URL`):

| Command | `base` | Delivery |
|---|---|---|
| `pnpm build` / `build:cloud` | `/` | Amplify, cloud-first resolver |
| `pnpm build:workstation` | `/pos/` | embedded in the workstation, **same-origin** resolver |

- **pos-web** (`godx-tempo-pos-web`): `vite.config.ts` sets `base` from the mode;
  `src/services/workstation/base-url-resolver.ts` treats `BASE_URL === "/pos/"`
  as "I am served BY the workstation" → the workstation URL is simply
  `location.origin`. No IP pairing, no stored URL.
- **workstation** (`godx-tempo-workstation-app`): `posweb.go` embeds the built
  bundle (`//go:embed all:web/pos/dist`); `internal/handler/server.go` mounts it
  at `/pos/` with `http.StripPrefix` over the existing SPA handler (Go 1.22
  ServeMux prefers `/pos/` over `/`, auto-redirects `/pos` → `/pos/`, and
  SPA-fallbacks client routes to `index.html`). The mount sits inside the
  existing `lanOnly + cors` ring — reachable on the LAN, blocked from the public
  Internet.

  The SPA fallback stops at anything that **looks like a file** (#1735): an
  unknown path WITH an extension 404s, only extension-less ones get
  `index.html`. pos-web's routes carry no extension, everything the bundle emits
  does. Without that line a tablet holding a stale `index.html` after a bundle
  rebuild asks for `assets/index-<old hash>.js`, gets `200` + HTML, and shows a
  blank POS with only a MIME error in the console instead of an honest 404.

### Which host does a call actually go to (#1511)

Three rules, and the bug they were written after — a release whose embedded POS
sent **every** `/api/v1/pos/*` call to the build-time `VITE_API_URL` and reported
「Chưa cấu hình phương thức thanh toán tại quầy」 in the payment dialog, while the
workstation's own mirror held the payment options the whole time:

1. **The workstation build defaults to LAN mode**, the cloud build to Cloud mode
   (`defaultMode()` in the resolver). The workstation SERVED the page, so it is
   reachable by construction and same-origin — that is the entire point of this
   feature. The old shared default ("cloud", LAN opt-in) was correct for Amplify
   and silently wrong here.
2. **Cloud mode still targets the backend directly** (#1481) — never a loopback
   through the workstation — and its URL comes from the workstation at RUNTIME:
   `posSPAHandler` injects `<meta name="x-pos-cloud-url">` into the served
   `index.html` from `WS_APP_CLOUD_URL`, and the resolver prefers it over the
   baked `VITE_API_URL`. One `.env` on the shop PC drives both pairing (via the
   relay) and Cloud mode, with no pos-web rebuild.
3. **A stored operator preference beats both** (Settings → Connection, and the
   shift-gate's "switch side" escape hatch). Only `VITE_POS_API_MODE` overrides
   the operator — leave it unset; `.env.workstation` says why.

A terminal flipped to Cloud during troubleshooting KEEPS that choice (it lives in
`localStorage.pos_api_mode`); flip it back in Settings → Connection.

## Multi-machine shop setup

1. Run the workstation (GUI `ws-app`, or the headless `ws-server`) on the shop
   PC. It advertises itself on the LAN via mDNS and writes its address to
   `<config dir>/endpoint.json` (also `GET /api/lan` from loopback).
2. On each tablet, either:
   - open **`http://<ws-ip>:<port>/pos`** in the browser (bookmark / home page), or
   - install the native shell **`app/pos`** (Expo) — it discovers the workstation
     via mDNS (`_ws-app._tcp`) or a manual URL, then loads the same `/pos` URL in
     a full-screen WebView. The shell does **not** ship a bundled copy of
     pos-web; updating POS still means updating the workstation embed.
3. The tablet pairs as a `pos` device inside pos-web; all POS + print traffic is
   same-origin `http` to the workstation, which proxies cloud-only calls upward.

`<port>` is the workstation's configured LAN server port (Settings → "Cổng server
cục bộ"); the address is whatever `endpoint.json` / the LAN banner shows.

## Build & embed pipeline

The bundle is **built into the binary**, not committed. The embed directory is
`workstation/pos-web/dist/` (`//go:embed all:pos-web/dist` in
`workstation/posweb.go`, which must sit at the module root for embed to reach
it). It is gitignored except a tracked `.gitkeep` so the embed always compiles;
the pipeline fills it with the real bundle before `go build`, and a build never
dirties the tree.

- **GUI release (the shop deliverable), from the umbrella:**
  ```sh
  cd workstation && make build          # runs `make posweb` first
  ```
  `make posweb` builds `POSWEB_SRC` (default `../web/pos`) with
  `pnpm build:workstation` and copies its `dist` into `workstation/pos-web/dist`.
  It **fails loud** if the source is absent, so a release can never silently ship
  a bundle-less binary. Override with `POSWEB_SRC=/path`, or skip with
  `POSWEB_OPTIONAL=1` (produces a bundle-less binary whose `/pos` 404s — for a
  Go-only iteration).
  The Wails path is wired too: `build/Taskfile.yml` `build:posweb`, and
  `build:server` + the darwin/linux/windows `build:native` depend on it.

- **CI (`ws-server` artifacts): currently not running.** The workstation CI
  workflow sits in `.github/workflows-parked/workstation-app-ci.yml`, a directory
  GitHub does not execute, and `workstation/.github/` does not exist at all.
  Its `pos-web` job still clones `godx-jp/godx-tempo-pos-web` over a
  `POSWEB_DEPLOY_KEY` deploy key — **obsolete**: pos-web is in-tree at
  `web/pos/`, so reviving the workflow means replacing that clone with a plain
  path build and dropping the secret. Until then the only pipeline that embeds a
  real bundle is the local `make build` above.

## The pos-web ↔ workstation route contract (T3.6 / T3.7)

So a route pos-web calls can never silently 404 on a tablet, the two apps share
a generated contract — the same golden-fixture-on-both-sides pattern as
`offline_signing_golden.json`:

1. **pos-web** generates `pos-api-manifest.json` from its service layer
   (`pnpm gen:api-manifest`, a fail-loud ts-morph pass over every `apiFetch` /
   `lanFetch` call). `pnpm check:api-manifest` gates it in pos-web CI.
2. **workstation** vendors a copy under
   `internal/handler/testdata/pos-api-manifest.json`; a Go parity test
   (`pos_api_manifest_parity_test.go`) source-parses `routes.go` and asserts the
   workstation serves **every** route in the manifest — by a local handler or a
   namespace catch-all proxy. `/api/v1/pos/*` has a catch-all; **`/api/lan/*`
   does not**, so a LAN route (e.g. a new print endpoint) that pos-web calls but
   the workstation doesn't register is a real 404 and turns the test red.

**When pos-web adds or changes a route:** run `pnpm gen:api-manifest` in
`web/pos`, then copy `pos-api-manifest.json` into
`workstation/internal/handler/testdata/` **in the same commit**. If the new route
is `/api/lan/*` and unhandled, the workstation parity test fails until a handler
lands — by design.

## Bundle version endpoint + v2 (future)

`pnpm build:workstation` stamps `dist/pos-bundle-version.json`
(`{bundle, version, commit, builtAt}`). The workstation serves it at
**`GET /api/lan/pos-bundle/version`** (public, like `/api/lan/health`). This is
the hook for **v2** — the workstation auto-pulling a newer bundle from Cloud so
tablets are never updated by hand — tracked as a separate follow-up issue. v1
ships the bundle with each workstation release.

## Field-test (offline evidence)

To prove a real shop is covered end-to-end (the evidence #1169 asks for), on the
shop LAN with the workstation paired:

1. From a **separate tablet**, open `http://<ws-ip>:<port>/pos` and confirm the
   POS loads and you can log in.
2. **Unplug the workstation's Internet** (leave the LAN switch/AP up).
3. On the tablet: create an order, take payment, and **print** — the kitchen
   ticket / receipt must come out of the Star printer.
4. `curl http://<ws-ip>:<port>/api/lan/pos-bundle/version` should return the
   embedded bundle's real `commit` (not `"unknown"`).

A local, single-machine equivalent (browser → `http://localhost:<port>/pos`,
serving the real bundle + the version endpoint) is what the PR verifies in CI/dev;
the printer + real-LAN step needs the shop hardware.

## Native shell loads `/pos`

`app/pos` is an Expo SDK 57 thin shell for iPad / Android tablets:

1. Boot reads a stored workstation base URL (or `EXPO_PUBLIC_WORKSTATION_URL`).
2. `GET /api/lan/health` must succeed before opening the WebView.
3. WebView navigates to `{baseUrl}/pos` — same same-origin contract as a browser.
4. Setup screen browses `_ws-app._tcp` (dev client / custom build only; Expo Go
   has no `react-native-zeroconf`) and accepts a manual `host:port`.
5. Five taps on the top-right corner open shell settings (change workstation /
   reload). Device pairing stays inside the WebView.

See `app/pos/CLAUDE.md`.

## See also

- `web/pos/CLAUDE.md` — build modes, resolver, security posture.
- `app/pos/CLAUDE.md` — native WebView shell.
- `workstation/CLAUDE.md` — security middleware ring, LAN print endpoints.
- [`printing.md`](printing.md) — the LAN print flow that this unblocks on
  multi-machine shops.
