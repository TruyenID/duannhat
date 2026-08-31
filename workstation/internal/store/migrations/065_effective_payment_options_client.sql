-- The LAN mirror of effective-payment-options never carried the POS client
-- capability block (requires_tendered / immediate_settlement /
-- supports_pos_checkout) nor the legacy PaymentMethod identity that Cloud's
-- PosEffectivePaymentOptionEnricher adds. pos-web reads
-- `option.client.supports_pos_checkout` UNCONDITIONALLY (checkoutCapableOptions),
-- so a LAN read that returned an effective option with a NULL client
-- white-screened the entire POS the instant an effective tender (e.g. cash)
-- appeared — which is every shift, right after open.
--
-- Add the enricher-parity columns. The mirror is replace-all (repopulated on
-- every pull tick), so existing rows need no backfill — they get rewritten in
-- full on the next successful pull. Reset the payment-policy sync cursor so
-- that pull happens immediately instead of short-circuiting on an unchanged
-- revision (which would leave `client_json` at '{}' until HQ next edits policy).

ALTER TABLE effective_payment_options ADD COLUMN method_type TEXT;
ALTER TABLE effective_payment_options ADD COLUMN legacy_payment_method_id TEXT;
ALTER TABLE effective_payment_options ADD COLUMN legacy_payment_method_code TEXT;
ALTER TABLE effective_payment_options ADD COLUMN client_json TEXT NOT NULL DEFAULT '{}';

DELETE FROM settings
WHERE key IN ('sync.payment_policy.revision', 'sync.payment_policy.snapshot_hash');
