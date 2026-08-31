-- plan-043 T4.4 — per-rate (軽減税率 / インボイス) columns on the local
-- customer_invoices mirror.
--
-- The LAN VAT-invoice print path (print_vat_invoice.go) needs to render per-rate
-- breakdown blocks (8%対象 / 10%対象) + the seller's T+13 registration number,
-- mirroring the cloud customer_invoices columns added in Phase 1 (T1.6). Both
-- are pulled DOWN by PullInvoices; the print reader (loadInvoiceForPrint) decodes
-- tax_breakdown JSON into per-rate rows and prints the registration number only
-- when present.
--
-- Safe DEFAULTs: pre-migration rows (and invoices synced from an OLD cloud that
-- omits these fields) read tax_breakdown = '[]' (→ the print falls back to the
-- single Thue line) and seller_registration_number = NULL (→ no reg-no line).

-- tax_breakdown — JSON array [{rate, taxable, tax}] frozen at issue time, the
-- per-rate mirror of the cloud customer_invoices.tax_breakdown column.
ALTER TABLE customer_invoices ADD COLUMN tax_breakdown TEXT NOT NULL DEFAULT '[]';

-- seller_registration_number — 適格請求書発行事業者登録番号 (T + 13 digits).
-- Nullable; null for now (Q5) so the reg-no line is suppressed on the slip.
ALTER TABLE customer_invoices ADD COLUMN seller_registration_number TEXT;
