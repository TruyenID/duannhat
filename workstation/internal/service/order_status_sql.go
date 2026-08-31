package service

// Terminal order statuses, as SQL tuples for the hand-written queries in this
// package and in internal/handler.
//
// `voided` is a staff cancellation (order.void, with a reason + voided_at).
// `expired` is CLOUD's auto-cancellation of a takeaway counter-pay order whose
// payment window elapsed (backend CancelOverdueTakeawayOrders, every minute) —
// the workstation never creates one itself, it arrives verbatim through
// pull-DOWN. That asymmetry is exactly why `expired` was missing from every
// filter until #149: an auto-cancelled takeaway sat on the active board
// forever and counted into a day's revenue it never actually took.
//
// Both mean the same thing to an operator: this order will never take money.
// Use these constants instead of inlining the tuple so the next terminal
// status lands in one place.
//
// NOT used by the void REPORTS (local_pos_revenue_voids.go, the 精算 report's
// void section): those audit staff voids specifically — they key on
// `voided_at`/`void_reason`, which an expired row does not have.
const (
	// SQLStatusCancelled — the order will never take money.
	SQLStatusCancelled = "('voided','expired')"
	// SQLStatusTerminal — cancelled, or settled and paid: no longer on the floor.
	SQLStatusTerminal = "('closed','voided','expired')"
)

// IsCancelledStatus reports whether a status is one an operator would read as
// "đã huỷ" — the Go-side twin of SQLStatusCancelled.
func IsCancelledStatus(status string) bool {
	return status == string(StatusVoided) || status == string(StatusExpired)
}
