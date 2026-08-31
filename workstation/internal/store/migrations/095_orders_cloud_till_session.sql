-- #2934 — Cloud already attributes every order to the cashier shift that was
-- open when the order was created.  The workstation pull used to discard that
-- field, leaving online-only Stripe / PayPay orders with no safe way to enter a
-- shift revenue report.
--
-- Keep the attribution on the ORDER, deliberately separate from
-- payments.till_session_id.  Customer-web money never entered the drawer and
-- must not become a local payment or affect expected_cash / till variance.

ALTER TABLE orders ADD COLUMN cloud_till_session_id TEXT;

CREATE INDEX IF NOT EXISTS idx_orders_cloud_till_session
    ON orders(cloud_till_session_id)
    WHERE cloud_till_session_id IS NOT NULL;
