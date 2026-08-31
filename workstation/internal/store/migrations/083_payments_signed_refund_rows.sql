-- Convert the cumulative `refunded_amount` column into signed refund ROWS
-- (#2656 — #2580 layer 2). Layer 1 (migration 082) added `refund_of_id` and made
-- the money READS representation-agnostic; this migration converts the data the
-- shop already has, and `local_pos_phase5.go` stops writing the column.
--
-- One refund = one append-only row, exactly like Cloud's `order_payments`:
-- `amount` negative, `refund_of_id` → the original, `status='succeeded'`. The
-- cumulative column could not express two partial refunds at different times by
-- different operators; `payment_refunds` (migration 006) already recorded each
-- one, so the conversion is driven by THAT table and every historical refund
-- keeps its own timestamp and note.
--
-- ─── The original keeps a LIVE status. This is the load-bearing decision ───
--
-- A workstation binary at version N must still read a database at version N+1:
-- migrations run before the health-check and have no down step, so after a
-- rollback the OLD binary reads THIS converted data. Its money query is
--
--     SUM(amount - COALESCE(refunded_amount, 0))
--     WHERE status IN ('pending','confirmed','succeeded')
--
-- so the original's status decides whether the drawer still balances:
--
--   · original stays 'succeeded' with refunded_amount zeroed, refund row
--     negative + 'succeeded'  ⇒ old binary sums  amount + (−X)  = correct net.
--   · original flipped/left at 'refunded'                       ⇒ old binary
--     DROPS the original and sees only −X — a negative drawer, and a shift that
--     cannot close.
--
-- Hence the UPDATE below also moves a fully-refunded original OFF 'refunded'
-- and back to 'succeeded'. "Fully refunded" is not lost: it is derivable from
-- the refund rows (and is derived — see `paymentRefundedSumSQL`). Reading
-- 'refunded' stays supported for rows Cloud writes (layer 3); the workstation
-- simply stops producing that shape itself.
--
-- The column is NOT dropped here. `refunded_amount` stays `NOT NULL DEFAULT 0`
-- so a rolled-back binary can still SELECT it in all eight places it does.
-- Dropping it is a separate issue, gated on "no deployed binary reads it".
--
-- Zeroing the column in the same breath is what stops a DOUBLE subtraction:
-- every current reader computes `amount - COALESCE(refunded_amount,0)`, so an
-- original that kept both a refund row AND its old cumulative figure would have
-- the refund taken off twice.

-- 1. One signed row per recorded refund, carrying that refund's own time + note.
--    `payment_refunds.id` becomes the refund row's payment id: one refund, one
--    identity, and it is the same id the `payment.refund` sync op already sends
--    to Cloud as `refund_id`.
INSERT INTO payments (
    id, order_id, payment_method, payment_method_id, amount, status,
    refund_of_id, till_session_id, note, idempotency_key, created_at, updated_at)
SELECT r.id, p.order_id, p.payment_method, p.payment_method_id, -r.amount, 'succeeded',
       p.id, p.till_session_id, r.note, 'refund:' || r.id, r.refunded_at, r.refunded_at
FROM payment_refunds r
JOIN payments p ON p.id = r.payment_id
WHERE p.refunded_amount > 0
  AND p.refund_of_id IS NULL
  AND r.amount > 0
  AND NOT EXISTS (SELECT 1 FROM payments x WHERE x.id = r.id);

-- 2. Residue. A payment whose `refunded_amount` exceeds the sum of the rows just
--    written has refunded money with no surviving history line. Money
--    conservation beats provenance: emit ONE row for the difference rather than
--    let the figure evaporate at step 3. Nothing local writes that shape today
--    (the handler always wrote both tables in one transaction), so this is
--    normally a no-op — but "normally" is not a guarantee to bet a drawer on.
INSERT INTO payments (
    id, order_id, payment_method, payment_method_id, amount, status,
    refund_of_id, till_session_id, note, idempotency_key, created_at, updated_at)
SELECT 'refund-residual-' || p.id, p.order_id, p.payment_method, p.payment_method_id,
       -(p.refunded_amount - COALESCE(
            (SELECT SUM(-x.amount) FROM payments x WHERE x.refund_of_id = p.id), 0)),
       'succeeded', p.id, p.till_session_id,
       'refunded_amount with no payment_refunds history (#2656 conversion)',
       'refund:residual:' || p.id,
       COALESCE(NULLIF(p.refunded_at, ''), p.updated_at),
       COALESCE(NULLIF(p.refunded_at, ''), p.updated_at)
FROM payments p
WHERE p.refunded_amount > 0
  AND p.refund_of_id IS NULL
  AND p.refunded_amount > COALESCE(
        (SELECT SUM(-x.amount) FROM payments x WHERE x.refund_of_id = p.id), 0)
  AND NOT EXISTS (SELECT 1 FROM payments y WHERE y.id = 'refund-residual-' || p.id);

-- 3. Retire the old representation on the originals: zero the column, drop the
--    full-refund marker, and put a fully-refunded original back into a status
--    every money query — this binary's and the previous one's — still counts.
UPDATE payments
SET refunded_amount = 0,
    refunded_at     = NULL,
    status          = CASE WHEN status = 'refunded' THEN 'succeeded' ELSE status END
WHERE refunded_amount > 0
  AND refund_of_id IS NULL;
