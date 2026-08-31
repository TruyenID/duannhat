package handler

import (
	"os"
	"regexp"
	"slices"
	"strings"
	"testing"
)

// Every LAN route must sit behind one of the auth rings the security section of
// CLAUDE.md describes. `lanOnly` covers the whole server, but that only keeps the
// public internet out — every device on the shop's wifi is inside it, guest
// phones included, so a route with no further wrapper is readable by all of them.
//
// #1258: /docs and /docs/openapi.yaml were the only two routes with no wrapper
// and no stated reason, publishing the full API surface — every endpoint,
// parameter and shape — while the two routes directly above them were localOnly
// precisely because their data is org-scoped.
//
// This is a source-level check on purpose. Go's ServeMux gives no way to ask a
// registered handler what wrapped it, and the property worth guarding is the one
// a reviewer would look for by eye and miss among 171 registrations.
func TestEveryRouteSitsBehindAnAuthRing(t *testing.T) {
	source, err := os.ReadFile("routes.go")
	if err != nil {
		t.Fatalf("read routes.go: %v", err)
	}

	unwrapped, registrations := scanUnwrappedRoutes(string(source), authRingExemptions())

	// Vacuity floor. Everything above is a source-level regex, so a rename of
	// `mux.Handle` or a reshuffle of routes.go into another file would make the
	// scan find nothing at all — and finding nothing is exactly what a clean
	// tree looks like. 195 registrations at the time of writing; the floor is
	// far below that on purpose, it only has to tell "scanning" from "blind".
	if registrations < 100 {
		t.Fatalf(
			"only %d route registrations found in routes.go — this guard reads the file with a regex, "+
				"so a rename of mux.Handle/mux.HandleFunc or a move of the registrations elsewhere leaves it "+
				"scanning nothing while still reporting green. Fix the scan, do not lower this floor.",
			registrations,
		)
	}

	if len(unwrapped) > 0 {
		t.Errorf(
			"these LAN routes have no auth wrapper, so every device on the shop wifi can reach them: %v\n"+
				"Wrap them (local/posAuth/kdsAuth/authedTypes/...), or add them to the exempt map above with the reason.",
			unwrapped,
		)
	}
}

// TestEveryAuthRingExemptionIsLoadBearing removes each exemption in turn and
// requires the route to be reported. An entry that changes nothing is worse
// than no entry: it reads like a considered decision while guarding nothing,
// and it silently pre-authorises whatever that pattern becomes next.
//
// This is not hypothetical. `"/api/v1/auth/": "token exchange, proxied to Cloud"`
// lived here until #3190 and was pure decoration: routes.go registers it as
// `corsForBrowser(cloudPassthrough)`, which the explicit cloudPassthrough
// `continue` below already skips AND the wrapper regex already matches — twice
// over. Leave-one-out proved it: dropping that entry kept the test green, while
// dropping the live `/ws` entry turned it red. Had anyone later re-registered
// /api/v1/auth/ WITHOUT cloudPassthrough — the whole login surface, unwrapped —
// this file would have stayed quiet.
func TestEveryAuthRingExemptionIsLoadBearing(t *testing.T) {
	source, err := os.ReadFile("routes.go")
	if err != nil {
		t.Fatalf("read routes.go: %v", err)
	}

	exempt := authRingExemptions()
	if len(exempt) == 0 {
		t.Fatal("the exemption map is empty — either the map moved or this ratchet lost its subject")
	}

	for pattern := range exempt {
		reduced := make(map[string]string, len(exempt))
		for k, v := range exempt {
			if k != pattern {
				reduced[k] = v
			}
		}

		unwrapped, _ := scanUnwrappedRoutes(string(source), reduced)
		if !slices.Contains(unwrapped, pattern) {
			t.Errorf(
				"exemption %q is not load-bearing: removing it changes nothing, so it is not what keeps "+
					"this test green. Either the route no longer exists in routes.go, or it already carries a "+
					"real wrapper (or cloudPassthrough) and the entry is decoration. Delete the entry — leaving "+
					"it there pre-authorises whatever %q is registered as next.",
				pattern, pattern,
			)
		}
	}
}

// authRingExemptions lists the routes deliberately left unwrapped, each with
// the reason recorded where it lives. Adding to this list should be as
// uncomfortable as it looks — and TestEveryAuthRingExemptionIsLoadBearing
// makes sure every line here is actually doing something.
func authRingExemptions() map[string]string {
	return map[string]string{
		// Auth happens in the first-message handshake instead, reusing the same
		// cache + stale ladder as HTTP (see ws.go).
		"/ws": "authMiddlewareVerifier at the handshake",
		// Cached image bytes carry no PII beyond their URL, which the requesting
		// tablet already holds by virtue of the menu payload (see lan_images.go).
		"GET /api/lan/images/{hash}": "content-addressed image cache, no PII",
		// Public by design: mDNS discovery healthcheck. Wrapped in corsForBrowser
		// only, which is an origin allow-list and NOT authentication — listed
		// here rather than counted as guarded, so the distinction stays visible.
		"GET /api/lan/health":     "mDNS discovery healthcheck, public by design",
		"OPTIONS /api/lan/health": "CORS preflight for the healthcheck",
		// Public by design (#1169): exposes only the embedded pos-web bundle's
		// build id (version/commit/builtAt) — strictly less than health, which
		// already publishes branch_id + store name. A monitoring/ops probe may
		// read it before any session exists; corsForBrowser only, no auth.
		"GET /api/lan/pos-bundle/version": "pos-web bundle build id, public by design",
		// CORS preflight. Browsers send no credentials on a preflight, so
		// requiring auth here would break every browser client — these must be
		// unauthenticated, and they expose nothing but the allow-list headers.
		"OPTIONS /api/device/":    "CORS preflight",
		"OPTIONS /api/v1/pos/":    "CORS preflight",
		"OPTIONS /api/v1/kds/":    "CORS preflight",
		"OPTIONS /api/lan/print/": "CORS preflight",
		// `/api/v1/auth/` (the login / token-exchange path) used to be listed
		// here. It is registered as corsForBrowser(cloudPassthrough), which the
		// scan already skips on the cloudPassthrough rule, so the entry never
		// decided anything — see TestEveryAuthRingExemptionIsLoadBearing.
	}
}

// scanUnwrappedRoutes reads routes.go the way a reviewer would and returns the
// registrations with no auth ring, plus how many registrations it saw at all —
// the second value is what tells a clean tree apart from a blind scan.
func scanUnwrappedRoutes(source string, exempt map[string]string) (unwrapped []string, registrations int) {
	// Not anchored after the comma: a registration may compose rings, e.g.
	// `s.paymentLimiter.Middleware(s.authedTypes(...))`, where the outermost
	// name is the limiter and the auth sits inside it. Anchoring reported that
	// line as unwrapped — a false alarm on a route that is in fact the most
	// heavily guarded one in the file.
	// corsForBrowser and corsMiddleware are deliberately NOT here. They are
	// origin allow-lists, not authentication: a route wrapped only in CORS is
	// still readable by anything on the LAN that sets no Origin header at all.
	// Counting them would have let a future unauthenticated route pass.
	// `authMW` is the Bearer-verify middleware the pos-web/kds proxy routes use
	// directly (`s.authMW.Wrap(...)`); `cloudPassthrough` forwards verbatim to
	// Cloud, which authenticates the request itself.
	wrapper := regexp.MustCompile(`\b(local|localOnly|posAuth|kdsAuth|rateLimit|authed|authedTypes|authMW|paymentLimiter|pairLimiter|cloudPassthrough)\b`)
	// A registration whose wrapper sits on the following line — the argument list
	// is split when it is long.
	registration := regexp.MustCompile(`mux\.Handle(Func)?\("([^"]+)",(.*)$`)

	lines := strings.Split(source, "\n")

	for i, line := range lines {
		m := registration.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		pattern, rest := m[2], m[3]
		registrations++

		if _, ok := exempt[pattern]; ok {
			continue
		}
		// cloudPassthrough registrations name the handler alone; Cloud performs
		// its own authentication on the forwarded request.
		if strings.Contains(rest, "cloudPassthrough") {
			continue
		}
		if wrapper.MatchString(line) {
			continue
		}
		// Long registrations put the handler on the next line.
		if strings.TrimSpace(rest) == "" && i+1 < len(lines) && wrapper.MatchString(lines[i+1]) {
			continue
		}

		unwrapped = append(unwrapped, pattern)
	}

	return unwrapped, registrations
}
