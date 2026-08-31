package handler

import (
	"os"
	"strings"
	"testing"
)

// Gate 8 ruling A (#1804): the 釣銭機 speaks HTTP/JSON on the LAN with no TLS and
// an IP allowlist, so the workstation is the SOLE host of the driver and every
// LAN client reaches the machine through this bridge. "Every LAN client" has to
// include the kiosk, and the kiosk cannot use the /pos/* mount: `policyPosWeb`
// deliberately refuses kiosk/tms device tokens for per-surface isolation, so a
// kiosk calling it gets 403.
//
// Source-level like TestEveryRouteSitsBehindAnAuthRing, and for the same reason —
// Go's ServeMux cannot be asked what wrapped a registration. The property worth
// pinning is that the two mounts stay in step: someone adding a fourth
// cash-changer endpoint for the POS and forgetting the kiosk re-creates exactly
// the asymmetry this test exists to remove.
func TestCashChangerBridgeIsMountedForBothSurfaces(t *testing.T) {
	source, err := os.ReadFile("routes.go")
	if err != nil {
		t.Fatalf("read routes.go: %v", err)
	}
	src := string(source)

	suffixes := []string{
		`cash-changer/collect"`,
		`cash-changer/collect/{session}"`,
		`cash-changer/collect/{session}/cancel"`,
	}

	for _, suffix := range suffixes {
		posMount := `/api/v1/pos/` + suffix
		kioskMount := `/api/v1/kiosk/` + suffix

		if !strings.Contains(src, posMount) {
			t.Errorf("POS mount missing: %s", posMount)
		}
		if !strings.Contains(src, kioskMount) {
			t.Errorf("kiosk mount missing: %s — the kiosk cannot reach the machine any "+
				"other way (the driver is LAN-only and the workstation is its sole host), "+
				"and it cannot borrow the /pos/* mount because policyPosWeb refuses kiosk "+
				"device tokens", kioskMount)
		}
	}

	// The kiosk mounts must carry the kiosk policy — widening policyPosWeb to
	// admit kiosk tokens instead would erase the per-surface isolation that
	// routes.go states as deliberate.
	for _, line := range strings.Split(src, "\n") {
		if !strings.Contains(line, `/api/v1/kiosk/cash-changer/`) {
			continue
		}
		if !strings.Contains(line, "policyKiosk") {
			t.Errorf("kiosk cash-changer route is not behind policyKiosk:\n  %s", strings.TrimSpace(line))
		}
	}
}
