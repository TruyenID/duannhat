package service

import (
	"strings"
	"testing"
)

// plan-055 T3.3 (#1829) — Cloud reads `gateway_option_id`, not
// `payment_option_id`. This mismatch was invisible: an unread request field
// produces no error, so every workstation payment lost its policy identity at
// the Cloud boundary and the policy check downstream was silently skipped.
func TestAppendPaymentPolicyFieldsEmitsCanonicalNames(t *testing.T) {
	body := map[string]any{}
	appendPaymentPolicyFieldsToBody(body, map[string]any{
		"payment_option_id": "opt-1",
		"connection_id":     "conn-1",
		"policy_revision":   7,
	})

	if body["gateway_option_id"] != "opt-1" {
		t.Fatalf("gateway_option_id = %v, want opt-1", body["gateway_option_id"])
	}
	if body["gateway_connection_id"] != "conn-1" {
		t.Fatalf("gateway_connection_id = %v, want conn-1", body["gateway_connection_id"])
	}
	if body["policy_revision"] != 7 {
		t.Fatalf("policy_revision = %v, want 7", body["policy_revision"])
	}
}

// The legacy names stay on the wire so this build still works against a Cloud
// that has not deployed the alias yet — deploy order must not be a constraint.
func TestAppendPaymentPolicyFieldsKeepsLegacyNames(t *testing.T) {
	body := map[string]any{}
	appendPaymentPolicyFieldsToBody(body, map[string]any{
		"payment_option_id": "opt-1",
		"connection_id":     "conn-1",
	})

	if body["payment_option_id"] != "opt-1" || body["connection_id"] != "conn-1" {
		t.Fatalf("legacy names dropped: %#v", body)
	}
}

// An absent field must not become an empty canonical one: an empty string fails
// Cloud's `uuid` rule and would turn a payment that used to succeed into a 422.
func TestAppendPaymentPolicyFieldsSkipsEmpty(t *testing.T) {
	body := map[string]any{}
	appendPaymentPolicyFieldsToBody(body, map[string]any{"payment_option_id": ""})

	if _, ok := body["gateway_option_id"]; ok {
		t.Fatalf("empty legacy value produced a canonical field: %#v", body)
	}
}

// The 422 that cost a shop ¥2,145 in one evening.
//
// Cloud validates `terminal_response` at max:1000 and keeps it only as an audit
// note (PaymentController). The P400's VescaJS OutputCompleteEvent is bigger than
// that, so every card payment.create was rejected outright, retried five times
// and dead-lettered. Cloud therefore never learned the card had been charged: it
// kept the order open, pos-web in Cloud mode showed it unpaid, and the cashier
// swiped again — four ¥715 charges on one ¥715 order, every one of them real.
//
// The note is what yields, never the payment.
func TestBoundTerminalResponseKeepsThePaymentSyncable(t *testing.T) {
	short := `{"SlipNumber":"SLIP-9","ApprovalCode":"OK"}`
	if got := boundTerminalResponse(short); got != short {
		t.Errorf("a response Cloud already accepts must pass through untouched, got %q", got)
	}

	got := boundTerminalResponse(strings.Repeat("x", 4096))
	if len(got) > maxTerminalResponse {
		t.Errorf("len = %d, want <= %d — above it Cloud rejects the WHOLE payment", len(got), maxTerminalResponse)
	}
	if !strings.HasSuffix(got, "…[truncated]") {
		t.Error("a clipped audit note must say it is clipped, or it reads as the complete terminal response")
	}

	exact := strings.Repeat("y", maxTerminalResponse)
	if boundTerminalResponse(exact) != exact {
		t.Error("a response exactly at Cloud's limit was truncated needlessly")
	}
}
