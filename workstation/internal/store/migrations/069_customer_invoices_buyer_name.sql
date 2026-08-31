-- #1304 tầng 4 (#1310) — buyer_name on the local customer_invoices mirror.
--
-- The official GTGT red invoice may be issued to a walk-in individual (khách
-- lẻ) who is NOT a stored customer: the buyer's personal name rides the invoice
-- as snapshot text instead of a customer_id (VN NĐ 123/2020 + TT 78 — a private
-- individual's invoice carries no buyer MST). Cloud added the column in #1307;
-- this mirrors it DOWN so loadInvoiceForPrint can render the "Ten:" line in the
-- NGUOI MUA block. Nullable: an empty value prints an underline for the cashier
-- to hand-write, exactly like the non-official RedInvoiceDialog slip.
ALTER TABLE customer_invoices ADD COLUMN buyer_name TEXT;
