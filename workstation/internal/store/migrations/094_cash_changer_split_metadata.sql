-- #2942 — keep split-bill audit context across the asynchronous Glory flow.
--
-- The workstation records the payment after the machine reaches `finish`, and
-- may restart between those two events. Storing this on the durable session is
-- therefore the only way to guarantee recovery writes the same `split_mode`,
-- bill index and allocation context as the live path.
--
-- Empty string means a normal, non-split cash collection. The recorder omits
-- split keys for that value; it never invents `none` or an empty split mode.
ALTER TABLE cash_changer_sessions
    ADD COLUMN payment_metadata TEXT NOT NULL DEFAULT '';
