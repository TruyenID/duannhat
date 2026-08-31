package handler

import (
	"testing"
)

// The 釣銭機 deposit window is the length of time the machine physically holds
// a customer's cash. Cloud enforces min:30 / max:86400
// (PeripheralDeviceService::metadataRulesFor); the workstation's own write
// path must refuse the same values, or the operator saves 10, the UI keeps
// showing 10, cashChangerDepositTimeout() silently substitutes 300s, and the
// sync-UP of that row 422s against Cloud forever.
func TestValidateDepositTimeout(t *testing.T) {
	cases := []struct {
		name    string
		raw     any
		wantErr bool
	}{
		{name: "absent is fine — Cloud's rule is `sometimes`", raw: nil, wantErr: false},
		{name: "lower bound", raw: float64(30), wantErr: false},
		{name: "upper bound", raw: float64(86400), wantErr: false},
		{name: "typical", raw: float64(600), wantErr: false},
		{name: "int (Go-side caller)", raw: 120, wantErr: false},
		{name: "below the bound — the reported bug", raw: float64(10), wantErr: true},
		{name: "zero", raw: float64(0), wantErr: true},
		{name: "negative", raw: float64(-1), wantErr: true},
		{name: "above the bound", raw: float64(86401), wantErr: true},
		{name: "fractional seconds", raw: 30.5, wantErr: true},
		{name: "string", raw: "600", wantErr: true},
		{name: "bool", raw: true, wantErr: true},
		{name: "NaN", raw: nan(), wantErr: true},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := validateDepositTimeout(tc.raw)
			if tc.wantErr && got == "" {
				t.Fatalf("validateDepositTimeout(%v) = accepted, want rejected", tc.raw)
			}
			if !tc.wantErr && got != "" {
				t.Fatalf("validateDepositTimeout(%v) = %q, want accepted", tc.raw, got)
			}
		})
	}
}

func nan() float64 {
	zero := 0.0
	return zero / zero
}

// The bound must be reachable through the actual write path, not just the
// helper — the form binds its inline error to this response.
func TestValidatePeripheral_RejectsOutOfRangeDepositTimeout(t *testing.T) {
	meta := map[string]any{"host": "192.168.1.90", "deposit_timeout_seconds": float64(10)}
	if msg := validatePeripheral("Glory", "coin_changer", meta); msg == "" {
		t.Fatal("validatePeripheral accepted a 10s deposit timeout; Cloud rejects it with min:30")
	}

	meta["deposit_timeout_seconds"] = float64(600)
	if msg := validatePeripheral("Glory", "coin_changer", meta); msg != "" {
		t.Fatalf("validatePeripheral rejected a valid 600s timeout: %s", msg)
	}
}

// A non-coin_changer peripheral carrying the key is not the coin changer's
// business — the bound applies where the machine is.
func TestValidatePeripheral_IgnoresDepositTimeoutOnOtherTypes(t *testing.T) {
	meta := map[string]any{"host": "192.168.1.91", "deposit_timeout_seconds": float64(1)}
	if msg := validatePeripheral("P400", "payment_terminal", meta); msg != "" {
		t.Fatalf("payment_terminal must not be gated on the deposit window: %s", msg)
	}
}
