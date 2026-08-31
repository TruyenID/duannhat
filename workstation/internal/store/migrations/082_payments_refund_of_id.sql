-- Append-only refund rows on the local payments ledger (#2580, layer 1).
--
-- Cloud (`order_payments`) has always represented a refund as a NEW row:
-- `status='succeeded'`, NEGATIVE `amount`, `refund_of_id` → the original, and
-- the original flipped to `status='refunded'`. The workstation instead carried a
-- CUMULATIVE `refunded_amount` column on the original row (migration 006) and had
-- no concept of a refund row at all.
--
-- Two models for the same money is what produced #2580: a refund created on Cloud
-- (pos-web's LAN→Cloud fallback in `web/pos/src/lib/api.ts`, or any SSO caller on
-- `POST /api/v1/{pos,shops/…}/orders/{o}/payments/{p}/refund`) is not representable
-- here at all, so the 精算 screen — which is served by THIS database, not Cloud —
-- shows the pre-refund drawer figure and the cashier cannot balance.
--
-- A financial sub-ledger is append-only (ADR #1151): a refund is a new signed
-- entry, never a mutation of the original. `refunded_amount` cannot express two
-- partial refunds at different times by different operators, and it destroys the
-- audit trail. Cloud already has the right model; the workstation is the divergent
-- one, so the workstation moves.
--
-- THIS migration only opens the door: it adds the column and the money READS
-- become representation-agnostic (see `paidPaymentsPredicate`). Nothing writes
-- `refund_of_id` yet — the refund WRITE path (`local_pos_phase5.go`) and the
-- conversion of existing `refunded_amount` data are layer 2, and the transport
-- that actually carries a Cloud-side refund down here is layer 3. Splitting is
-- deliberate: every reader of this table is money-bearing and they cannot all be
-- reviewed in one diff.
--
-- Offline-first is untouched. The workstation stays the source of truth for
-- payments at sale time and still syncs UP (`payment.refund` op, sync_service.go);
-- adopting Cloud's SHAPE is not adopting Cloud's authority.
ALTER TABLE payments ADD COLUMN refund_of_id TEXT;

CREATE INDEX IF NOT EXISTS idx_payments_refund_of ON payments(refund_of_id)
    WHERE refund_of_id IS NOT NULL;
