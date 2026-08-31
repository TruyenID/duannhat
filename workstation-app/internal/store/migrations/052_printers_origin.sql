-- Plan-047 — printer source tracking for cloud-first printer config.
--
-- `origin` splits the printer registry into two independent lanes:
--   'local' — added by hand in the WS App (tab Thiết bị). The cloud pull NEVER
--             touches these; they are the offline fallback that keeps a shop
--             printing when Cloud is unreachable.
--   'cloud' — mirrored from the Cloud printer config (admin-web → shop printers)
--             by PullPrinters. Cloud is source-of-truth: the pull replaces the
--             whole set of origin='cloud' rows each cycle.
--
-- Existing rows predate the cloud feed and were all added locally, so they
-- default to 'local' and stay untouched by the sync.
ALTER TABLE printers ADD COLUMN origin TEXT NOT NULL DEFAULT 'local';

CREATE INDEX IF NOT EXISTS idx_printers_origin ON printers (origin);
