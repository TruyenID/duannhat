package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// plan-055 T3.1/T3.2 (#1830) — the HANDLER half of the proof.
//
// `TestResolvedPaymentOptionIDAcceptsEveryClientWireName` pins the accessor,
// but it stays green even if no handler ever calls it. Only a request through
// the real LAN route proves the option id survives all the way into the
// payments row — which is what sync-UP later forwards to Cloud.
//
// The bug this locks down was invisible: `encoding/json` ignores an unknown key
// without complaint, so a kiosk sending `option_id` produced a perfectly
// successful 201 with a NULL option id in the row.
//
// Falsification: revert either handler to `input.PaymentOptionID` and the
// matching subtest fails with an empty stored id.
func TestLANPaymentKeepsOptionIDWhateverTheClientCallsIt(t *testing.T) {
	cases := []struct {
		name  string
		field string
	}{
		{name: "kiosk sends option_id", field: "option_id"},
		{name: "pos-web sends gateway_option_id", field: "gateway_option_id"},
		{name: "workstation's own canonical name", field: "payment_option_id"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
			s, db := newServerWithAuth(t, cloud.URL)
			mux := http.NewServeMux()
			s.registerLocalReplicaRoutes(mux)

			// The option must exist in the mirror: resolvePaymentPolicyIdentity
			// refuses to invent identity for an id it cannot find, and that
			// fail-closed behaviour is deliberate — keep it in the loop rather
			// than asserting against a value the resolver never validated.
			seedEffectiveOptionRow(t, s, nil, "kiosk", "opt-cash", 1)

			body, _ := json.Marshal(map[string]any{
				"order_id":       "order-1",
				"payment_method": "card",
				"amount":         1500,
				tc.field:         "opt-cash",
			})
			req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
			req.Header.Set("Authorization", "Bearer kiosk-token")
			req.Header.Set("Idempotency-Key", "idem-"+tc.field)
			rec := httptest.NewRecorder()
			mux.ServeHTTP(rec, req)

			if rec.Code != http.StatusCreated {
				t.Fatalf("expected 201, got %d body=%s", rec.Code, rec.Body.String())
			}

			var stored string
			if err := db.QueryRow(
				`SELECT COALESCE(payment_option_id, '') FROM payments WHERE idempotency_key = ?`,
				"idem-"+tc.field,
			).Scan(&stored); err != nil {
				t.Fatalf("read payment row: %v", err)
			}

			if stored != "opt-cash" {
				t.Fatalf("payments.payment_option_id = %q, want opt-cash — the %s name was dropped at the LAN boundary", stored, tc.field)
			}
		})
	}
}

// A client that names no option must stay unaffected: enforcement is not on yet
// and a payment without policy identity is still legal, so the aliasing must not
// invent one.
func TestLANPaymentWithoutAnyOptionNameStoresNothing(t *testing.T) {
	cloud := mockKioskMeCloud(t, "kiosk-1", "branch-A")
	s, db := newServerWithAuth(t, cloud.URL)
	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	body, _ := json.Marshal(map[string]any{
		"order_id":       "order-1",
		"payment_method": "card",
		"amount":         1500,
	})
	req := httptest.NewRequest("POST", "/api/v1/kiosk/payments", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer kiosk-token")
	req.Header.Set("Idempotency-Key", "idem-none")
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d body=%s", rec.Code, rec.Body.String())
	}

	var stored string
	if err := db.QueryRow(
		`SELECT COALESCE(payment_option_id, '') FROM payments WHERE idempotency_key = ?`,
		"idem-none",
	).Scan(&stored); err != nil {
		t.Fatalf("read payment row: %v", err)
	}

	if stored != "" {
		t.Fatalf("payments.payment_option_id = %q, want empty", stored)
	}
}
