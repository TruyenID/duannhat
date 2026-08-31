package service

import (
	"os"
	"regexp"
	"testing"
)

// validTransitions calls itself "cloud-aligned". Until #1268 it covered 8 of
// Cloud's 10 statuses: `expired` had a constant but no row, and
// `awaiting_confirmation` had neither.
//
// A missing row is not a compile error and not a runtime error either — it makes
// CanTransitionTo refuse every target, which reads as "this order is finished".
// For a genuinely terminal state that is right by accident; for
// awaiting_confirmation it meant a counter-pay order pulled down from Cloud sat
// on the POS list and could not be accepted, failing with a message naming the
// internal state machine.
//
// So the generated enum is the source of truth here: every status Cloud can
// send must have a row, even if that row is empty. An empty row is a decision
// ("terminal"); a missing one is silence.
func TestEveryCloudStatusHasATransitionRow(t *testing.T) {
	// Read the generated enum rather than importing it: the point is to notice
	// when codegen ADDS a status, and a compile-time reference would not.
	source, err := os.ReadFile("../domain/generated/enums/customer_order_status.go")
	if err != nil {
		t.Fatalf("read generated enum: %v", err)
	}

	values := regexp.MustCompile(`CustomerOrderStatus\w+\s+CustomerOrderStatus = "([a-z_]+)"`).
		FindAllStringSubmatch(string(source), -1)
	if len(values) < 8 {
		t.Fatalf("only %d statuses parsed from the generated enum — the scan is broken, not the enum", len(values))
	}

	for _, m := range values {
		status := Status(m[1])
		if _, ok := validTransitions[status]; !ok {
			t.Errorf(
				"Cloud status %q has no row in validTransitions.\n"+
					"A missing row silently refuses every transition, which is indistinguishable from\n"+
					"'terminal'. If it IS terminal, say so with an empty row. See #1268.",
				status,
			)
		}
	}
}

// The two rows #1268 added, pinned to what Cloud actually does rather than to
// what looked reasonable: commitAwaitingConfirmation writes pending,
// voidAwaitingConfirmation writes voided, and nothing else moves the status.
func TestAwaitingConfirmationMirrorsCloud(t *testing.T) {
	for _, target := range []Status{StatusPending, StatusVoided} {
		if !StatusAwaitingConfirmation.CanTransitionTo(target) {
			t.Errorf("awaiting_confirmation should be able to reach %s — Cloud writes it", target)
		}
	}
	for _, target := range []Status{StatusOpen, StatusDining, StatusCheckout, StatusPaying, StatusClosed} {
		if StatusAwaitingConfirmation.CanTransitionTo(target) {
			t.Errorf("awaiting_confirmation must NOT reach %s — Cloud has no path that writes it", target)
		}
	}
	if len(validTransitions[StatusExpired]) != 0 {
		t.Errorf("expired is terminal; it gained %d transitions", len(validTransitions[StatusExpired]))
	}
}
