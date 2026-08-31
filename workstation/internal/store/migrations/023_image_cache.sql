-- LAN image cache.
--
-- Workstation downloads product / SKU / topping / gallery images from
-- Cloud and serves them off its own LAN HTTP server. Without this,
-- pos-web in LAN mode resolves every <img src> to the Cloud URL
-- (MinIO / S3 path) — works while online, dies the moment the
-- workstation loses internet. Caching the bytes locally lets the LAN
-- mode render thumbnails / carousels / cart line images entirely
-- offline.
--
-- Schema decisions:
--   * Keyed by url_hash (SHA-256 hex of the source URL). Stable across
--     restarts + safe to put in URLs (no escaping) + dedupes when the
--     same image is referenced by gallery + product.image_url +
--     sku.image_url (galleryFirst).
--   * `bytes` is BLOB. Image payloads are < 1 MB on average; even at
--     5 MB the BLOB column on modernc.org/sqlite handles cleanly.
--     ImageFetcher bounds individual entries at 5 MB to keep memory
--     pressure on the LAN tablet flow bounded.
--   * `etag` lets the fetcher issue conditional GETs — Cloud's S3-style
--     URLs typically carry an ETag. Hit → no body re-download, just
--     refresh fetched_at.
--   * `last_referenced_at` is updated by the read handler on every
--     serve so the LRU eviction can pick the right victims when the
--     cache approaches its byte budget.
--
-- NOT a sync DOWN target — the fetcher writes here, never the puller.

CREATE TABLE IF NOT EXISTS pos_image_cache (
    url_hash             TEXT PRIMARY KEY,         -- SHA-256 hex of source_url
    source_url           TEXT NOT NULL,
    content_type         TEXT,
    etag                 TEXT,
    bytes                BLOB NOT NULL,
    size                 INTEGER NOT NULL,
    fetched_at           TEXT NOT NULL DEFAULT (datetime('now')),
    last_referenced_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_image_cache_referenced
    ON pos_image_cache(last_referenced_at);
CREATE INDEX IF NOT EXISTS idx_pos_image_cache_fetched
    ON pos_image_cache(fetched_at);
