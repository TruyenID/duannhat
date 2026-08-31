// Package workstation — embedded pos-web bundle (#1169).
//
// The workstation serves the pos-web SPA at /pos so shop tablets on the LAN
// reach it over plain http, same-origin with the LAN API — killing the
// mixed-content wall that blocked every LAN feature (print, etc.) when tablets
// loaded pos-web from Amplify HTTPS. See internal/handler/server.go (mount) and
// docs/guide/workstation-serves-pos-web.md.
//
// This file must live at the repo root so go:embed can reach pos-web/dist. Like
// frontend/dist, that directory is gitignored except a tracked .gitkeep so this
// always compiles; the build pipeline fills it with the real bundle before
// `go build` (make posweb, or the CI pos-web-dist artifact). A binary built
// without the bundle serves 404 at /pos and reports version "unknown".
//
// `all:` is required — a plain `//go:embed pos-web/dist` excludes the dotfile
// .gitkeep, and an otherwise-empty dir would then fail to compile.
package workstation

import "embed"

//go:embed all:pos-web/dist
var PosWebAssets embed.FS
