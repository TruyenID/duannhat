-- 063 — plan-053 M3 (#1171): the local print-template cache.
--
-- Cloud resolves system → brand → shop ONCE per branch and sends the answer
-- (see PrintTemplateReplicaController); this table is that answer, kept on disk
-- so a shop that loses the internet keeps printing exactly what it printed
-- while it had it (TR-14 / nguyên tắc #5 — nothing about a template may ever
-- stop a sale).
--
-- Rows are keyed (kind, version) and NEVER updated in place: a published
-- version is immutable upstream (TR-08), and keeping the older rows is what
-- makes a reprint honest — 再発行 must use the version the original was printed
-- with (TR-28), not whatever is current.
--
-- effective_from is a BRANCH WALL CLOCK string ("YYYY-MM-DD HH:MM:SS"), NOT an
-- instant (#1091). Storing it as a timestamp would drag it through a timezone
-- conversion and move the version switchover by exactly the branch's offset —
-- the same class of bug that stamped nine hours of every JST day with the
-- previous business date. Comparison is a plain string compare against the
-- branch's own wall clock, which is correct because the format sorts.
--
-- checksum is Cloud's sha256 over the canonical definition. A row only lands
-- after the workstation recomputes it and agrees (TR-24) — a truncated download
-- must never be able to replace a working template.
CREATE TABLE IF NOT EXISTS print_templates (
    kind              TEXT    NOT NULL,
    version           INTEGER NOT NULL,
    scope             TEXT    NOT NULL DEFAULT 'system',
    definition        TEXT    NOT NULL,
    effective_from    TEXT,
    checksum          TEXT    NOT NULL,
    is_system_default INTEGER NOT NULL DEFAULT 0,
    cloud_updated_at  TEXT,
    fetched_at        TEXT    NOT NULL,
    PRIMARY KEY (kind, version)
);

-- The print-time lookup is "newest version of this kind already in force",
-- which is exactly this index.
CREATE INDEX IF NOT EXISTS idx_print_templates_kind_effective
    ON print_templates (kind, effective_from, version);
