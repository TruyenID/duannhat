package handler

import (
	"encoding/json"
	"os"
	"regexp"
	"strings"
	"testing"
)

// TestPosApiManifestParity is the workstation half of the pos-web ↔ workstation
// route contract (godx-tempo#1169 T3.7). pos-web generates pos-api-manifest.json
// — every {method, path} it calls — and we vendor a copy under testdata/. This
// test asserts the workstation SERVES every route in that manifest, either by a
// local handler registered in routes.go or by the explicit Cloud-only allowlist.
// A route pos-web starts calling that the workstation cannot answer then
// turns THIS test red the moment the vendored manifest is bumped — instead of
// silently 404'ing on a shop tablet.
//
// POS catch-all presence is not considered coverage: the allowlist in
// pos_cloud_proxy.go is the contract. This makes a newly introduced path fail
// here until its LAN ownership is decided explicitly.
//
// When pos-web adds/changes a route: `pnpm gen:api-manifest` in pos-web, then
// copy pos-api-manifest.json into this repo's testdata/ (the submodule bump
// ritual) — the same golden-fixture-in-both-repos pattern as
// offline_signing_golden.json.

type manifestRoute struct {
	Method string `json:"method"`
	Path   string `json:"path"`
}

type posApiManifest struct {
	RouteCount int             `json:"route_count"`
	Routes     []manifestRoute `json:"routes"`
}

// A route registered with an explicit method, e.g. mux.Handle("GET /api/v1/pos/me", …).
var localRouteRe = regexp.MustCompile(`mux\.Handle(?:Func)?\(\s*"([A-Z]+)\s+(/[^"]+)"`)

// A method-less subtree catch-all, e.g. mux.Handle("/api/v1/pos/", …proxy). These
// forward every unmatched path in the subtree to Cloud.
var catchAllRe = regexp.MustCompile(`mux\.Handle(?:Func)?\(\s*"(/[^"\s]+/)"`)

type registeredRoute struct {
	method string
	segs   []string
}

func TestPosApiManifestParity(t *testing.T) {
	raw, err := os.ReadFile("testdata/pos-api-manifest.json")
	if err != nil {
		t.Fatalf("read vendored manifest: %v", err)
	}
	var m posApiManifest
	if err := json.Unmarshal(raw, &m); err != nil {
		t.Fatalf("parse manifest: %v", err)
	}
	// Anti-truncation guard: a manifest cut short (or an empty vendored file)
	// must not let this test pass vacuously.
	if len(m.Routes) == 0 || len(m.Routes) != m.RouteCount {
		t.Fatalf("manifest has %d routes but route_count=%d — a truncated fixture would pass vacuously",
			len(m.Routes), m.RouteCount)
	}

	local, _ := parseRegisteredRoutes(t)

	var uncovered []string
	for _, r := range m.Routes {
		if !routeCovered(r, local, nil) {
			uncovered = append(uncovered, r.Method+" "+r.Path)
		}
	}

	if len(uncovered) > 0 {
		t.Fatalf(
			"pos-web calls %d route(s) the workstation does not serve (no local handler or declared Cloud fallback):\n  %s\n\n"+
				"Add a local handler in routes.go, or explicitly add the Cloud-owned route to cloudOnlyPOSRoutes. "+
				"For /api/lan/* there is no catch-all — a missing handler is a real 404 on the tablet.",
			len(uncovered), strings.Join(uncovered, "\n  "))
	}
}

// TestPosApiLocalHandlerVerbsMatchTheManifest is the OTHER direction, and it
// exists because the test above cannot fail on the thing that actually shipped.
//
// `routeCovered` step 2 accepts any `/api/v1/pos/*` path via the catch-all,
// method-agnostic — correctly, because the proxy really does serve it. So a
// local handler registered under the WRONG VERB is invisible: pos-web's call
// does not match it, falls through to Cloud, Cloud answers, everything looks
// healthy. The handler is dead code, and the only symptom is that the offline
// behaviour it was written for never happens — which surfaces during an outage,
// the one moment nobody is running tests.
//
// That is #1986: `POST …/sessions/{id}/draft` while pos-web and Cloud both use
// PATCH. Every draft save had been going to Cloud since the day it was written.
//
// The rule is PER PATH, not per verb: a path whose local handlers are ALL
// registered under verbs pos-web never calls is dead code. Anything stronger
// produces false positives on shapes that are deliberate — the first draft of
// this test asserted "every called verb must have a local handler" and
// immediately flagged `PATCH /pos/settings/order`, which is correct as it
// stands: the workstation serves `GET` from its replica and lets the WRITE go
// to Cloud, because a settings write applied locally would create a second,
// divergent truth. Read-from-replica / write-to-authority is a pattern, not a
// bug, and a guard that cannot tell it from #1986 would be turned off.
//
// Worked through on the three cases in the tree today:
//
//	draft          local {POST}      called {PATCH}       ∅ → dead, and this is #1986
//	settings/order local {GET}       called {GET, PATCH}  {GET} → alive, write proxies on purpose
//	split-bill     local {GET, POST} called {GET}         {GET} → alive, the spare verb harms nothing
func TestPosApiLocalHandlerVerbsMatchTheManifest(t *testing.T) {
	raw, err := os.ReadFile("testdata/pos-api-manifest.json")
	if err != nil {
		t.Fatalf("read vendored manifest: %v", err)
	}
	var m posApiManifest
	if err := json.Unmarshal(raw, &m); err != nil {
		t.Fatalf("parse manifest: %v", err)
	}
	if len(m.Routes) == 0 || len(m.Routes) != m.RouteCount {
		t.Fatalf("manifest has %d routes but route_count=%d", len(m.Routes), m.RouteCount)
	}

	local, _ := parseRegisteredRoutes(t)

	var localPos []registeredRoute
	for _, l := range local {
		if len(l.segs) >= 4 && l.segs[0] == "api" && l.segs[1] == "v1" && l.segs[2] == "pos" {
			localPos = append(localPos, l)
		}
	}
	// Anti-vacuity: if the pos handlers ever move out of routes.go this test
	// must say so rather than quietly asserting nothing.
	if len(localPos) < 10 {
		t.Fatalf("only %d local /pos handlers found — the parser or the file layout changed", len(localPos))
	}

	var dead []string
	for _, l := range localPos {
		// Which verbs does pos-web use on the path this handler claims?
		var called []string
		for _, want := range m.Routes {
			if segMatch(l.segs, splitSegs(want.Path)) {
				called = append(called, want.Method)
			}
		}
		if len(called) == 0 {
			// pos-web never calls this path. The handler may exist for kiosk,
			// KDS or a future caller — out of scope for a pos-web contract.
			continue
		}

		reachable := false
		for _, l2 := range localPos {
			if !sameSegs(l.segs, l2.segs) {
				continue
			}
			for _, c := range called {
				if l2.method == c {
					reachable = true
				}
			}
		}
		if !reachable {
			dead = append(dead, l.method+" /"+strings.Join(l.segs, "/")+
				" (pos-web uses "+strings.Join(called, ",")+")")
		}
	}

	if len(dead) > 0 {
		t.Fatalf(
			"%d local handler(s) no call can ever reach:\n  %s\n\n"+
				"pos-web's request does not match the registered verb, so it falls through to the "+
				"Cloud proxy — which answers, which is why nothing looks broken. The handler only "+
				"mattered with the internet down, and with the internet down it does not run. "+
				"Register the verb Cloud registers.",
			len(dead), strings.Join(dead, "\n  "))
	}
}

// sameSegs reports whether two registered patterns name the same route.
func sameSegs(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}

	return true
}

func parseRegisteredRoutes(t *testing.T) ([]registeredRoute, []string) {
	t.Helper()
	src, err := os.ReadFile("routes.go")
	if err != nil {
		t.Fatalf("read routes.go: %v", err)
	}
	text := string(src)

	var local []registeredRoute
	for _, mt := range localRouteRe.FindAllStringSubmatch(text, -1) {
		local = append(local, registeredRoute{method: mt[1], segs: splitSegs(mt[2])})
	}

	var catchAll []string
	for _, mt := range catchAllRe.FindAllStringSubmatch(text, -1) {
		catchAll = append(catchAll, mt[1]) // e.g. "/api/v1/pos/"
	}
	return local, catchAll
}

func routeCovered(r manifestRoute, local []registeredRoute, catchAll []string) bool {
	segs := splitSegs(r.Path)
	// 1) A local handler whose method matches and whose pattern matches the path.
	for _, l := range local {
		if l.method == r.Method && segMatch(l.segs, segs) {
			return true
		}
	}
	// 2) A POS route explicitly reviewed as Cloud-owned.
	if strings.HasPrefix(r.Path, "/api/v1/pos/") && posCloudRouteAllowed(r.Method, r.Path) {
		return true
	}
	// 3) A non-POS namespace catch-all subtree proxy (method-agnostic).
	for _, prefix := range catchAll {
		if prefix != "/api/v1/pos/" && strings.HasPrefix(r.Path, prefix) {
			return true
		}
	}
	return false
}

// segMatch reports whether a registered pattern's segments match a concrete
// path's segments. A registered `{name}` is a single-segment wildcard; a
// registered `{name...}` matches the segment and everything after it. A
// registered literal must equal the path segment exactly (so a registered
// literal never matches a `{param}` the manifest normalised a dynamic segment
// to — that path is covered by a wildcard route or the catch-all instead).
func segMatch(pattern, pathSegs []string) bool {
	i := 0
	for ; i < len(pattern); i++ {
		p := pattern[i]
		if strings.HasPrefix(p, "{") && strings.HasSuffix(p, "...}") {
			return true // multi-segment wildcard swallows the rest
		}
		if i >= len(pathSegs) {
			return false
		}
		if isWildcardSeg(p) {
			continue
		}
		if p != pathSegs[i] {
			return false
		}
	}
	return i == len(pathSegs)
}

func isWildcardSeg(s string) bool {
	return strings.HasPrefix(s, "{") && strings.HasSuffix(s, "}")
}

func splitSegs(p string) []string {
	p = strings.Trim(p, "/")
	if p == "" {
		return nil
	}
	return strings.Split(p, "/")
}
