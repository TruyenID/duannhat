package domain

import (
	"encoding/json"
	"testing"
)

// plan-055 T3.1/T3.2 (#1830) — the LAN clients send three different names for
// the effective-payment-option id, and the workstation only ever read one.
//
// `encoding/json` ignores an unknown key without complaint, so the identity was
// dropped at the LAN boundary with no error and no log: every payment taken
// through a workstation stored a NULL option id. That is also why the sync-UP
// fix in #1829 had nothing to forward.
//
// Falsification: drop either alias field from CreatePaymentInput and the
// matching subtest reports an empty id — the production symptom exactly.
func TestResolvedPaymentOptionIDAcceptsEveryClientWireName(t *testing.T) {
	cases := []struct {
		name string
		body string
		want string
	}{
		{
			name: "pos-web sends Cloud's canonical name (it also talks to Cloud directly)",
			body: `{"order_id":"o1","amount":100,"gateway_option_id":"OPT"}`,
			want: "OPT",
		},
		{
			name: "kiosk sends its own short name",
			body: `{"order_id":"o1","amount":100,"option_id":"OPT"}`,
			want: "OPT",
		},
		{
			name: "workstation's own canonical name still works",
			body: `{"order_id":"o1","amount":100,"payment_option_id":"OPT"}`,
			want: "OPT",
		},
		{
			name: "a client that sends none is unchanged — absence must stay absence",
			body: `{"order_id":"o1","amount":100}`,
			want: "",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			var in CreatePaymentInput
			if err := json.Unmarshal([]byte(tc.body), &in); err != nil {
				t.Fatalf("unmarshal: %v", err)
			}

			if got := in.ResolvedPaymentOptionID(); got != tc.want {
				t.Fatalf("ResolvedPaymentOptionID() = %q, want %q", got, tc.want)
			}
		})
	}
}

// A body carrying more than one name can only come from a client bug. The most
// specific name wins, so the result never depends on JSON key order.
func TestResolvedPaymentOptionIDPrefersTheCanonicalName(t *testing.T) {
	var in CreatePaymentInput
	body := `{"payment_option_id":"CANON","gateway_option_id":"GW","option_id":"SHORT"}`
	if err := json.Unmarshal([]byte(body), &in); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if got := in.ResolvedPaymentOptionID(); got != "CANON" {
		t.Fatalf("ResolvedPaymentOptionID() = %q, want CANON", got)
	}

	var gwFirst CreatePaymentInput
	if err := json.Unmarshal([]byte(`{"gateway_option_id":"GW","option_id":"SHORT"}`), &gwFirst); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if got := gwFirst.ResolvedPaymentOptionID(); got != "GW" {
		t.Fatalf("ResolvedPaymentOptionID() = %q, want GW", got)
	}
}
