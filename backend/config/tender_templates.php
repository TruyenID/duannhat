<?php

/*
 * #1156 — Vendor tender-acceptance templates for payment terminals.
 *
 * Keyed by terminal model slug (lowercase). Each entry lists the
 * `till_tender_types.tender_key` values that the vendor's STANDARD contract
 * accepts. Used purely as a PREFILL when a `payment_terminal` peripheral is
 * registered with `metadata.model` but without an explicit
 * `metadata.accepts` — the operator can (and should) edit the result to
 * match the shop's actual acquirer contract, since brand line-ups vary per
 * contract (e.g. a Stera merchant may not have WeChat Pay enabled).
 *
 * Keys must stay within the vendor-neutral vocabulary that
 * TillTenderTypeSeeder plants org-wide; at prefill time the list is
 * intersected with the organization's active org-level tender keys, so a
 * template can never smuggle a key the org's vocabulary does not know.
 */

return [

    // SMBC GMO "stera terminal" — full JP brand line-up: credit, the major
    // QR wallets, and contactless e-money (iD / transit IC / Edy / WAON /
    // nanaco / QUICPay).
    'stera' => [
        'credit',
        'paypay',
        'rakuten_pay',
        'd_barai',
        'au_pay',
        'merpay',
        'id',
        'ic',
        'edy',
        'waon',
        'nanaco',
        'quicpay',
    ],

    // Netstars StarPay — credit + domestic/overseas QR wallets (strong on
    // inbound: WeChat Pay / Alipay / UnionPay), no contactless e-money.
    'starpay' => [
        'credit',
        'paypay',
        'au_pay',
        'wechat_pay',
        'alipay',
        'unionpay',
    ],

];
