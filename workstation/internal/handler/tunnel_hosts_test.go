package handler

import (
	"sync"
	"testing"
)

// resetTunnelHosts re-arms the sync.Once so a test can install its own
// WS_APP_TUNNEL_HOSTS value. Production reads the env exactly once at first use.
func resetTunnelHosts(t *testing.T, value string) {
	t.Helper()
	t.Setenv(tunnelHostsEnv, value)
	tunnelHostsOnce = sync.Once{}
	tunnelHostsList = nil
	t.Cleanup(func() {
		tunnelHostsOnce = sync.Once{}
		tunnelHostsList = nil
	})
}

// The default posture must be unchanged: with the env unset, every guard stays
// exactly as strict as it was before tunnel support existed. This is what
// production ships, so it is the important half of the feature.
func TestTunnelHosts_UnsetKeepsGuardsStrict(t *testing.T) {
	resetTunnelHosts(t, "")

	if isTunnelHost("sealed-qld-falling-widespread.trycloudflare.com") {
		t.Error("tunnel host allowed while WS_APP_TUNNEL_HOSTS is unset")
	}
	if wsHostIsLANOrLoopback("sealed-qld.trycloudflare.com") {
		t.Error("WS host guard opened up without opt-in")
	}
	if isAllowedOrigin("https://sealed-qld.trycloudflare.com") {
		t.Error("credentialed-origin check opened up without opt-in")
	}
	if originAllowed("https://sealed-qld.trycloudflare.com") {
		t.Error("CORS origin check opened up without opt-in")
	}

	// The pre-existing allowances must survive.
	if !wsHostIsLANOrLoopback("192.168.1.202:8080") || !wsHostIsLANOrLoopback("localhost:8080") {
		t.Error("LAN/loopback WS hosts must still pass")
	}
	if !originAllowed("https://pos.godx.jp") {
		t.Error("*.godx.jp must still pass")
	}
}

func TestTunnelHosts_SuffixEntryOptsIn(t *testing.T) {
	resetTunnelHosts(t, ".trycloudflare.com")

	const host = "sealed-qld-falling-widespread.trycloudflare.com"
	if !isTunnelHost(host) {
		t.Fatal("configured suffix did not match")
	}
	if !wsHostIsLANOrLoopback(host + ":443") {
		t.Error("WS handshake still refuses the configured tunnel Host")
	}
	if !isAllowedOrigin("https://" + host) {
		t.Error("credentialed-origin check refuses the configured tunnel origin")
	}
	if !originAllowed("https://" + host) {
		t.Error("CORS refuses the configured tunnel origin")
	}
}

// A dot-anchored entry must not be satisfiable by gluing the suffix onto
// another registrable domain — the same anchoring bug the .godx.jp check
// documents.
func TestTunnelHosts_SuffixIsDotAnchored(t *testing.T) {
	resetTunnelHosts(t, ".trycloudflare.com")

	for _, host := range []string{
		"eviltrycloudflare.com",
		"trycloudflare.com.attacker.example",
		"attacker.example",
	} {
		if isTunnelHost(host) {
			t.Errorf("%q must not match the .trycloudflare.com suffix", host)
		}
	}
}

func TestTunnelHosts_ExactEntryAndCasing(t *testing.T) {
	resetTunnelHosts(t, "pos.staging.example, .tunnel.test ")

	if !isTunnelHost("POS.Staging.Example") {
		t.Error("exact entry must match case-insensitively")
	}
	if isTunnelHost("other.staging.example") {
		t.Error("exact entry must not match a sibling host")
	}
	if !isTunnelHost("a.tunnel.test") {
		t.Error("whitespace around a list entry must be trimmed")
	}
	// An exact entry is not a suffix rule.
	if isTunnelHost("x.pos.staging.example") {
		t.Error("exact entry must not behave as a suffix")
	}
}

// Non-HTTPS tunnel origins stay rejected by the CORS layer — the scheme check
// in originAllowed is independent of the host allow-list.
func TestTunnelHosts_CORSStillRequiresHTTPS(t *testing.T) {
	resetTunnelHosts(t, ".trycloudflare.com")

	if originAllowed("http://sealed-qld.trycloudflare.com") {
		t.Error("plain-http tunnel origin must not pass the CORS check")
	}
}
