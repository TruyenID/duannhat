-- Per-locale display names for the two LAN feeds behind the POS payment
-- dialog: the tender-brand chips (till_tender_types) and the payment-method
-- buttons above them (effective_payment_options).
--
-- Both mirrors stored ONE name each, because Cloud resolves a translatable
-- attribute against the request locale and `cloudGet` sends no
-- Accept-Language — so every pull landed `config('app.locale')` (en) and every
-- terminal in the shop read English no matter which UI language its cashier
-- had picked. Pinning a locale on the pull would not fix that, it would only
-- choose whose language is wrong: one workstation serves a whole floor of
-- terminals and each has its own operator. The locale belongs to the READER,
-- so the mirror holds every locale and the LAN handler resolves per request
-- from Accept-Language — the same shape pos_products.name_{ja,en,vi} already
-- uses for menu item names.
--
-- The base `name` / `display_name` columns stay as the fallback for rows a
-- shop never translated, and for a Cloud older than the feeds that emit
-- `name_i18n` / `display_name_i18n`.

ALTER TABLE till_tender_types ADD COLUMN name_ja TEXT;
ALTER TABLE till_tender_types ADD COLUMN name_en TEXT;
ALTER TABLE till_tender_types ADD COLUMN name_vi TEXT;

ALTER TABLE effective_payment_options ADD COLUMN display_name_ja TEXT;
ALTER TABLE effective_payment_options ADD COLUMN display_name_en TEXT;
ALTER TABLE effective_payment_options ADD COLUMN display_name_vi TEXT;

-- till_tender_types is replace-all on every pull tick, so it refills itself.
-- effective_payment_options is NOT: its pull short-circuits on an unchanged
-- (revision, fingerprint) cursor, and the fingerprint folds in only revisions,
-- hashes and option COUNTS — never option contents. Adding a field to the
-- Cloud payload therefore does not move it, and without this reset the new
-- columns would sit NULL until HQ next edits payment policy. Same reasoning as
-- 065_effective_payment_options_client.sql.
DELETE FROM settings
WHERE key IN ('sync.payment_policy.revision', 'sync.payment_policy.snapshot_hash');
