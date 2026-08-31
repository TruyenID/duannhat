-- #1282 — the printed receipt lost its 支払方法 line for any order paid ONLINE.
--
-- Such a payment is confirmed in Cloud (customer-web / Stripe / PayPay /
-- konbini); the workstation only learns the order went `closed` on the next
-- pull-DOWN and auto-prints from the onOrderPaid hook. It has no local
-- `payments` row for it, so paymentMethodDisplay() resolved "" and the line
-- was silently dropped — exactly on the slip a customer takes home.
--
-- This column is a DISPLAY-ONLY mirror of Cloud's `payment_summary`: a JSON
-- array of {id, payment_method_id, payment_method_code, payment_method_name,
-- amount}. It is deliberately NOT a row in `payments`, because that table
-- feeds the Z-report / 精算 aggregation (paidPaymentsPredicate counts rows
-- with a NULL till_session_id inside the shift window) and the plan-044 gap
-- reconciliation panel. An online payment materialized there would show up as
-- claimable till cash and move real money.
--
-- Nothing reads this column except the receipt formatter's label lookup, and
-- nothing in sync-UP writes it, so it cannot reach Cloud.

ALTER TABLE orders ADD COLUMN cloud_payment_summary TEXT;
