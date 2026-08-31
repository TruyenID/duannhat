package service

import "time"

// plan-053 M3 (#1171) — the print path's clock.
//
// The legacy formatters stamp a slip with `time.Now()`. That is correct at
// runtime (the workstation runs on the shop PC, so its wall clock IS the shop's
// — see the umbrella CLAUDE.md "Business time vs display time" section), but it
// makes the TR-40 golden migration gate racy: the gate renders the same slip
// twice (once through the hard-coded formatter, once through the definition
// renderer) and compares BYTES, so a minute boundary crossing between the two
// calls would fail a run for a reason that has nothing to do with the template.
//
// Routing both paths through one seam removes the race without changing any
// behaviour: printNow defaults to time.Now and is only ever swapped by tests.
var printNow = time.Now

// orderTransactionTime resolves 取引年月日 — the moment of the SALE (#2065).
//
// ## The field is about the transaction, not about the paper
//
// 取引年月日 is a mandatory field of a 適格簡易請求書 (消法57条の4②). Stamping it
// from the clock at PRINT time makes a REPRINT — including a sheet already
// marked 「再印刷 #2」 — claim the transaction happened today. The slip stays
// well-formed and correctly laid out; only its content is wrong, and only on
// the copy someone reached for precisely because they needed to reconcile
// something. That is why this was invisible: the byte gates compare fixtures
// with a frozen clock, so both sides agreed on a value that was wrong together.
//
// #1091 already settled the principle for this exact class of bug ("dating an
// offline sale": a sale made at 20:00 and synced next morning keeps its real
// time). The print layer was going the other way.
//
// ## Why the PAYMENT moment, and why this ladder
//
//	ClosedAt → CheckoutAt → OpenedAt
//
// A 領収書 certifies that money was RECEIVED, so the payment instant is the
// transaction it attests to — not when the table was opened. `ClosedAt` first
// and `CheckoutAt` second is not a new opinion: it is the ladder
// `handler.printTablePaidSlip` already uses for the same question (auto_print.go).
//
// `OpenedAt` is the last rung rather than an error because it is the one
// timestamp an order ALWAYS has (set at create), while `ClosedAt`/`CheckoutAt`
// are nil on the sync-down and raw-close paths. For a restaurant the two fall on
// the same business day except across a night shift — and on that shift the sale
// time is still enormously closer to the truth than the print time, which may be
// days later.
//
// Deliberately NOT using BusinessClock-style date logic here: this returns an
// INSTANT. Which ZONE that instant is written down in is the caller's problem,
// and `orderSlipTime` below is where it gets answered — see the note there for
// why "the shop PC's wall clock" was true of one rung of the ladder and false
// of the other.
//
// Returns ok=false when the order carries no usable instant at all, so the
// caller can decide its own fallback.
func orderTransactionTime(o *Order) (time.Time, bool) {
	if o == nil {
		return time.Time{}, false
	}
	if o.ClosedAt != nil && !o.ClosedAt.IsZero() {
		return *o.ClosedAt, true
	}
	if o.CheckoutAt != nil && !o.CheckoutAt.IsZero() {
		return *o.CheckoutAt, true
	}
	if !o.OpenedAt.IsZero() {
		return o.OpenedAt, true
	}
	return time.Time{}, false
}

// orderSlipTime is orderTransactionTime with the print clock as last resort,
// expressed in the SHOP's zone.
//
// The fallback exists for slips with no order behind them at all; it is NOT a
// soft default. Both the legacy formatters and PrintRenderData.now() route
// through this ONE function so the TR-40 gate cannot be satisfied by two paths
// that merely happen to agree on a fixture.
//
// ## Why the `.In(loc)` is not cosmetic (#2572)
//
// The two rungs of this ladder used to return times in DIFFERENT ZONES, and
// nothing said so:
//
//	printNow()            → time.Now(), i.e. the shop PC's zone
//	o.ClosedAt/CheckoutAt → parsed by `time.Parse(time.RFC3339, "…Z")` in
//	                        orderEngine.scanOrder, i.e. **UTC**
//
// So the rung that fires for every real sale printed UTC. A VN shop (+07)
// selling at 21:30 printed "14:30", and a sale at 01:00 local printed the
// PREVIOUS calendar day — in 取引年月日, a mandatory field of a 適格簡易請求書
// (消法57条の4②). Wrong hour is embarrassing; wrong date is a defective tax
// document.
//
// The instant is never changed, only the zone it is written down in — which is
// exactly what #1091 asks of a display layer.
//
// `loc` is threaded in explicitly rather than read from a global, the same way
// `handler.businessDayRangeIn` takes its location: a formatter that reaches for
// an ambient zone is how the shift report and the receipt ended up disagreeing
// about what "now" means in the first place. nil is resolved by
// `slipLocation`.
func orderSlipTime(o *Order, loc *time.Location) time.Time {
	if t, ok := orderTransactionTime(o); ok {
		return t.In(slipLocation(loc))
	}
	return printNow().In(slipLocation(loc))
}

// slipDatesFromPrintClock splits the two questions a date on paper can answer,
// which #2065 answered as one (chủ dự án 2026-08-17).
//
//	kitchen · runner · delta_qr  →  WHEN THIS SHEET CAME OUT
//	everything else              →  WHEN THE TRANSACTION HAPPENED
//
// The three named kinds are WORK ORDERS: the kitchen reads the time to sequence
// what to cook, the pass reads it to know how long a bag has been sitting. On an
// open table `orderTransactionTime` falls to `OpenedAt`, so a round fired two
// hours into a meal printed the time the table was seated — the one instant on
// that sheet nobody needed, and it looked plausible enough to go unnoticed.
//
// Everything else keeps the transaction time, and that half of #2065 stands
// unchanged and must: 取引年月日 is a mandatory field of a 適格簡易請求書 (消法
// 57条の4②), so a reprint that re-dated it would make the copy someone reached
// for — precisely because they needed to reconcile something — assert the sale
// happened today. For a settled order that instant is `ClosedAt`, stamped in the
// same statement as `paid_amount`, i.e. the moment the money landed. Reading it
// off a column rather than a clock is what makes a reprint keep it.
//
// `remaining` · `debt_slip` · `red_invoice` are deliberately NOT in the list.
// They are money documents — a balance owed, a debt on account, a 領収書 — and
// each attests to a transaction rather than to a task. Widening this to them is
// a separate decision, not an oversight.
func slipDatesFromPrintClock(kind string) bool {
	switch kind {
	case "kitchen", "runner", "delta_qr":
		return true
	}
	return false
}

// orderSlipTimeForKind is orderSlipTime with the split above applied. ONE seam
// for both print paths, same reason orderSlipTime is one: the TR-40 gate must
// not be satisfiable by two implementations that merely agree on a fixture.
func orderSlipTimeForKind(kind string, o *Order, loc *time.Location) time.Time {
	if slipDatesFromPrintClock(kind) {
		return printNow().In(slipLocation(loc))
	}
	return orderSlipTime(o, loc)
}

// slipLocation resolves the zone a slip's wall-clock lines are written in.
//
// nil → `time.Local`, the workstation's OS zone. That is the same first rung
// `handler.resolveShopLocation` prefers for the 精算 slip, and for the same
// reason: the workstation runs on the shop PC, so its clock is the one the
// cashier reads off the paper.
//
// The safety net that ladder has and this one does not — falling back to the
// Cloud-registered `branches.timezone` when the machine itself is set to UTC —
// lives in `internal/handler`, which owns the DB handle. Wiring it through to
// here is a separate change; see the note on PrintJobConfig.slipLoc.
func slipLocation(loc *time.Location) *time.Location {
	if loc == nil {
		return time.Local
	}
	return loc
}
