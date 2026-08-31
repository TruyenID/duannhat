package handler

// Refund representation on the local payments ledger (#2656 — #2580 layer 2).
//
// A refund is an append-only signed ROW: `amount` negative, `refund_of_id` → the
// original, `status='succeeded'`. The original is NEVER mutated — not its
// amount, not its status, not the retired `refunded_amount` column. That is the
// shape Cloud's `order_payments` has always had, and the shape a sub-ledger owes
// (ADR #1151): the cumulative column could not express two partial refunds at
// different times by different operators.
//
// Three consequences every query over `payments` now has to pick from:
//
//  1. AGGREGATES OF MONEY (drawer, Z-report, captured, overpay guard) want the
//     refund row IN, at its signed value. Most already are: a refund row carries
//     `status='succeeded'`, so `status IN ('pending','confirmed','succeeded')`
//     nets it automatically — which is exactly why the write path leaves the
//     original at 'succeeded' instead of flipping it to 'refunded'. Flip the
//     original and every one of those sums drops the sale but keeps the refund.
//
//  2. QUERIES THAT PICK OR COUNT "a payment" (is this order a split? which
//     payment's metadata / tendered cash / amount do I print? is there unsynced
//     money?) want the refund row OUT — it is not a collection. They carry
//     `AND refund_of_id IS NULL`.
//
//  3. CLIENT SHAPES keep the old field. pos-web renders `refunded_amount` as a
//     badge on the payment line and Cloud's own history merge filters negative
//     rows out, so the LOCAL history does the same: refund rows are hidden and
//     `refunded_amount` is DERIVED via paymentRefundedSumSQL. Deriving beats
//     storing — a second copy of that truth is what drifted in the first place.
//
// `refunded_amount` survives in the amount expression of the money aggregates on
// purpose, and it is not a legacy fallback: migration 083 zeroes every existing
// row, but a binary ROLLED BACK past this release still writes the column, and
// 083 will never run again to convert what it wrote. `amount - COALESCE(
// refunded_amount,0)` is correct under both representations because they can
// never coexist on one row. Dropping the column is a separate issue, gated on
// "no deployed binary reads it" (PR #2661 bans `DROP COLUMN` outright: a binary
// at version N must read the schema at version N+1).

// sqlOnlyOriginalPayments is the WHERE fragment for case 2 above — everything
// that means "a payment the shop collected", as opposed to a refund of one.
const sqlOnlyOriginalPayments = `refund_of_id IS NULL`

// paymentRefundedSumSQL is a scalar SQL expression yielding the total refunded
// against the payment aliased by `alias`: the signed refund rows, plus whatever
// a rolled-back binary may have left in the retired cumulative column. The two
// never coexist on one row, so adding them is a sum of one term.
//
// `alias` must be a query alias, never user input.
func paymentRefundedSumSQL(alias string) string {
	a := alias + "."
	return `(` + a + `refunded_amount + COALESCE((SELECT -SUM(r.amount) FROM payments r
		WHERE r.refund_of_id = ` + a + `id AND r.status = 'succeeded'), 0))`
}

// paymentLastRefundAtSQL yields the timestamp of the most recent refund row
// against `alias`, or the empty string — the derived replacement for the
// `refunded_at` marker the write path no longer stamps on the original.
func paymentLastRefundAtSQL(alias string) string {
	return `COALESCE((SELECT MAX(r2.created_at) FROM payments r2
		WHERE r2.refund_of_id = ` + alias + `.id AND r2.status = 'succeeded'), '')`
}
