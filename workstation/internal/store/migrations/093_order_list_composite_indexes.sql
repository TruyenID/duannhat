-- POS order feeds always scope to the workstation's paired branch and sort by
-- opened_at. Separate single-column indexes forced SQLite to filter with the
-- branch index then build a temporary B-tree for ORDER BY as history grew.
--
-- Keep status after the sort key: the default active feed uses NOT IN across
-- several terminal states, so placing status before opened_at would still lose
-- index order. Takeaway adds an equality order_type prefix and gets its own
-- ordered path.
CREATE INDEX IF NOT EXISTS idx_orders_branch_opened
    ON orders(branch_id, opened_at DESC);

CREATE INDEX IF NOT EXISTS idx_orders_branch_type_opened
    ON orders(branch_id, order_type, opened_at DESC);
