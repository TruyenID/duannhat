-- 025: normalize tables.status from workstation's earlier "available"
-- default to Cloud's canonical "free". Pos-web's TablePicker indexes
-- STATUS_STYLE by table.status; the unknown "available" key returned
-- undefined and crashed the picker when staff hit Đổi bàn → Ghép bàn.
-- Idempotent: only rewrites rows that match the legacy literal.

UPDATE tables SET status = 'free' WHERE status = 'available';
