# Legacy PayPay webhook relay

A WordPress must-use plugin that lives on **`menu.betoya.jp`**, not in this app.
It is kept here because it is the only copy under version control, and because
losing it means losing the reasoning below.

## Why it exists

PayPay configures webhooks **per merchant**, and Live merchant
`653886312490745856` is shared by two systems:

| System | Where |
|---|---|
| WooCommerce (`paypay4wc`, `enabled = yes`) | `menu.betoya.jp` |
| TempoFast | `tempo-prod.godx.jp` |

One merchant means one Live webhook slot. Pointing it at TempoFast would stop
the legacy shop confirming its own orders. So the slot stays where it is and
this plugin hands TempoFast a copy of every notification.

## The two rules it lives by

1. **It never changes what WooCommerce does.** Every failure is swallowed and
   the call is non-blocking. A broken tee must not become a broken payment: a
   throw here surfaces to PayPay as a failed callback and strands a paid order.
2. **It sends the original bytes.** TempoFast verifies the payload as PayPay
   sent it, so re-encoding the JSON would prove nothing.

## It posts to the ORIGIN on purpose

`tempo.godx.jp` sits behind CloudFront, and TempoFast verifies a PayPay OPA
webhook by source IP. Measured 2026-08-11 — three requests through the public
domain arrived as three different CloudFront edge addresses; the same request to
`tempo-prod.godx.jp` arrived as the caller's real one. An IP allowlist therefore
cannot match through the CDN, for this relay or for PayPay itself.

**This also decides the URL to register with PayPay**: give them the origin.

## Install

```sh
scp scripts/legacy-paypay-relay/tempo-paypay-relay.php \
    tempofast:'~/betoya.jp/public_html/menu.betoya.jp/wp-content/mu-plugins/'
```

`mu-plugins` auto-loads; there is nothing to activate.

## Verify

```sh
# merchant id is deliberately wrong, so WooCommerce exits without touching an order
curl -X POST -H 'Content-Type: application/json' \
  -d '{"notification_type":"Transaction","merchant_id":"000000000000000000","merchant_order_id":"relay-probe","state":"COMPLETED"}' \
  https://menu.betoya.jp/wc-api/paypay/
```

Then on TempoFast prod, the newest `payment_provider_events` row should carry
`verified_at` set and `outcome = paypay_no_matching_attempt` — verified, and
correctly finding no order for a fake merchant. A `Provider webhook signature
verification failed` line in `laravel.log` instead means the relay's egress IP
is missing from `PAYPAY_WEBHOOK_RELAY_IPS`; that line now names the `client_ip`
to add.

## When PayPay finally points Live at TempoFast

Nothing here needs changing and nothing breaks: TempoFast dedupes on
`(connection_id, environment, provider_event_id)`, so the same notification
arriving from both paths books once. Delete this plugin when the legacy shop is
retired — not before.
