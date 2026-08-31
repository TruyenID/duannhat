-- 026: Bring `payments` to Cloud's OrderPayment shape so pos-web's
-- PaymentDialog round-trips end-to-end in LAN mode. Pre-fix workstation
-- only stored {payment_method, amount}; pos-web sends payment_method_id
-- (UUID), tip/tendered/change_amount, reference_no, note + needs an
-- auto-confirm flow when method.is_auto_confirm = true. Without these
-- columns the local sync queue forwards an incomplete payload to Cloud
-- (Cloud falls back to defaults — silent drift).
--
-- All additions are NULL-tolerant so a pre-fix row still loads.

ALTER TABLE payments ADD COLUMN payment_method_id TEXT;
ALTER TABLE payments ADD COLUMN tip_amount        INTEGER NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN tendered_amount   INTEGER;
ALTER TABLE payments ADD COLUMN change_amount     INTEGER;
ALTER TABLE payments ADD COLUMN reference_no      TEXT;
ALTER TABLE payments ADD COLUMN note              TEXT;
ALTER TABLE payments ADD COLUMN paid_at           TEXT;
ALTER TABLE payments ADD COLUMN expires_at        TEXT;
ALTER TABLE payments ADD COLUMN till_session_id   TEXT;

CREATE INDEX IF NOT EXISTS idx_payments_method ON payments(payment_method_id);
