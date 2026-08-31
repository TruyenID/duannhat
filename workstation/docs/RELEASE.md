# Release build — ws-app

Produces downloadable builds for macOS, Windows and Linux, all pointed at the
production cloud.

## What decides the cloud URL

`defaultCloudAPIURL` in [`internal/config/config.go`](../internal/config/config.go)
— currently `https://tempo.godx.jp`. On first run the app writes it into
`~/.ws-app/config.json`, and `handleDevicePair` reads it from there to proxy the
pairing code to `POST {cloud}/api/v1/devices/pair`.

Consequences worth remembering:

- **It is only a default.** A machine that has already run the app keeps whatever
  is in its `config.json`. Re-pointing an existing install means editing that file
  (or deleting it), not shipping a new binary.
- **Local dev must opt out.** `make dev` and `wails3 task dev` both export
  `WS_APP_CLOUD_URL=http://localhost:5400` for this reason. Without it a dev
  pairing would burn a real pairing code on the live cloud.

## Prerequisites

- Go 1.25+, `wails3` (`~/go/bin/wails3`), pnpm, Docker (Linux targets only).
- Bump `APP_VERSION` in [`Taskfile.yml`](../Taskfile.yml) **and** `VERSION` in the
  [`Makefile`](../Makefile) — they are separate constants and both are used.
  `APP_VERSION` is what gets stamped into `config.Version` via `-ldflags -X`
  and surfaced on `GET /api/version`.

## Build

```sh
# macOS — universal (arm64 + amd64), .app bundle, ad-hoc signed
wails3 task darwin:package:universal          # → bin/ws-app.app

# Windows amd64 — cross-compiles natively from macOS/Linux (CGO off)
wails3 task windows:build ARCH=amd64          # → bin/ws-app.exe

# Linux — needs an ARCH-MATCHED docker image, see the trap below
docker build --platform linux/amd64 -t wails-cross:amd64 -f build/docker/Dockerfile.cross build/docker/
docker build --platform linux/arm64 -t wails-cross:arm64 -f build/docker/Dockerfile.cross build/docker/
wails3 task linux:build ARCH=amd64 OUTPUT=bin/ws-app-linux-amd64
wails3 task linux:build ARCH=arm64 OUTPUT=bin/ws-app-linux-arm64
```

### Trap: the Linux image arch must match the target arch

`build/docker/Dockerfile.cross` compiles Linux targets with the container's
**native gcc** — there is no zig wrapper for Linux, because the GTK/WebKit dev
headers are only installed for the image's own architecture. Building
`ARCH=amd64` inside an arm64 image (the default on Apple Silicon) fails with:

```
gcc: error: unrecognized command-line option '-m64'
```

Hence the arch-tagged images and the `--platform linux/{{.DOCKER_ARCH}}` flag in
`build/linux/Taskfile.yml`. amd64-on-arm64 runs emulated — slow, but correct.
Building the amd64 image on Apple Silicon takes ~15 min.

### Trap: `MAIN_PKG`

The stock Wails layout puts `main` at the repo root. This repo keeps the root as
package `workstation` (it only holds the `go:embed` handles for `frontend/dist`
and `migrations/omnify`) and puts `main` in `./cmd/workstation/`. The container
build script therefore needs `-e MAIN_PKG=./cmd/workstation/`, wired up in each
platform Taskfile. Without it `go build .` **succeeds** and silently emits a ~4 MB
`ar` archive instead of an executable — check `file` output, not just exit codes.

### Trap: `pnpm install --ignore-workspace`

This app lives in-tree in the tempo umbrella, whose `pnpm-workspace.yaml` does
not list `workstation/frontend`. A plain `pnpm install` walks up, resolves the
umbrella as the workspace root and installs *that* — leaving `frontend/node_modules`
untouched, so a fresh clone dies at `vite build`.

## Package

```sh
VER=0.1.0
mkdir -p dist
ditto -c -k --sequesterRsrc --keepParent bin/ws-app.app "dist/ws-app-${VER}-macos-universal.zip"
(cd bin && zip -q "../dist/ws-app-${VER}-windows-amd64.zip" ws-app.exe)
# Linux: binary + ws-app.desktop + ws-app.png per arch → tar.gz
(cd dist && shasum -a 256 ws-app-*.zip ws-app-*.tar.gz > SHA256SUMS.txt)
```

Use `ditto`, not `zip`, for the `.app` — a plain zip drops the code signature and
macOS then refuses to launch it.

## Verify before shipping

```sh
file bin/ws-app-linux-amd64                      # must say "ELF ... executable", not "ar archive"
lipo -info bin/ws-app.app/Contents/MacOS/ws-app  # must list x86_64 AND arm64
codesign -v --strict bin/ws-app.app              # must be "valid on disk"
strings -a bin/ws-app.exe | grep -c tempo.godx.jp   # must be > 0

# End-to-end: fresh config seeds prod URL, and pairing reaches the live cloud
WS_APP_CONFIG_DIR=$(mktemp -d) WS_APP_SERVER_PORT=18099 ./bin/ws-app.app/Contents/MacOS/ws-app &
curl -s http://localhost:18099/api/version
curl -s -X POST http://localhost:18099/api/device/pair \
  -H 'Content-Type: application/json' -d '{"pairing_code":"ZZZZZZ"}'
# expect Laravel's 422 "Invalid or expired pairing code" — proves it reached prod,
# not a connection error
```

## Signing status

Neither the macOS nor the Windows build is signed with a real certificate, so both
warn on download (Gatekeeper quarantine / SmartScreen). `dist/INSTALL.md` tells end
users how to get past it. To fix properly:

- **macOS**: set `SIGN_IDENTITY` + `KEYCHAIN_PROFILE` in `build/darwin/Taskfile.yml`,
  run `wails3 signing credentials …` once, then `wails3 task darwin:sign:notarize`.
- **Windows**: needs a purchased code-signing certificate; see the `vars` block in
  `build/windows/Taskfile.yml`.
