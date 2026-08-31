<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
        // plan-023 M4 T4.3 — HMAC shared secret for inbound webhook
        // verification. Generated in Postmark dashboard → Server →
        // Settings → Webhooks → Webhook validation token.
        'webhook_secret' => env('POSTMARK_WEBHOOK_SECRET'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // #2893 — the Stripe account `STRIPE_SECRET` authenticates AS (`acct_…`).
        // Two things read it, and both break in silence when it is wrong:
        //   1. a platform-account webhook (no `account` field on the event) is
        //      attributed to the connection whose `merchant_account_id` names
        //      this account — that is how settlement money finds its owner;
        //   2. `StripeConnectScope` sends NO `Stripe-Account` header for that
        //      connection, because our own account is not a CONNECTED account.
        // Leave it empty and both fall back to the pre-#2893 behaviour instead
        // of guessing. Read it through `StripePlatformAccount`, never raw.
        'account_id' => env('STRIPE_ACCOUNT_ID'),
        // #815 — the charge currency is resolved per-order from the branch's
        // ShopOrderSetting.currency_code. This env value is ONLY the fallback for a
        // branch that has no currency configured; it never overrides the branch.
        'currency' => env('STRIPE_CURRENCY', 'jpy'),
    ],

    'paypay' => [
        // Which PayPay host the SDK talks to. Explicit rather than derived from
        // APP_ENV: PayPay keys carry no live/test marker, so a production deploy
        // holding sandbox credentials must not be pushed at the live endpoint —
        // the normal posture during a pilot. Refused as `live` outside production.
        'environment' => env('PAYPAY_ENVIRONMENT', 'sandbox'),
        'api_key' => env('PAYPAY_API_KEY'),
        'api_secret' => env('PAYPAY_API_SECRET'),
        'merchant_id' => env('PAYPAY_MERCHANT_ID'),
        // Optional — only for local HMAC simulation; live OPA webhooks use IP allowlist.
        'webhook_secret' => env('PAYPAY_WEBHOOK_SECRET'),
        // TEMPORARY: forward signed OPA calls via hubsupport egress while PayPay
        // allowlists Tempo prod IP. Empty = direct (normal). Remove after allowlist.
        'egress_proxy_url' => env('PAYPAY_EGRESS_PROXY_URL'),
        'egress_proxy_token' => env('PAYPAY_EGRESS_PROXY_TOKEN'),
        /*
         * PayPay OPA webhook source IPs (integration.paypay.ne.jp FAQ 4414062832143).
         *
         * `PAYPAY_WEBHOOK_RELAY_IPS` (comma-separated) appends to the LIVE list
         * for a relay that replays a genuine PayPay delivery to us (#2445).
         *
         * Why an env var and not another hardcoded entry: PayPay's own IPs are a
         * published fact of the provider and belong in the repo; a relay host is
         * a fact of ONE deployment's topology, changes when that topology does,
         * and must not require a code deploy to correct. It also keeps the trust
         * widening visible in the environment where it applies, instead of
         * silently granting it in every environment that runs this code.
         *
         * The relay does not weaken the mechanism: whoever holds that IP could
         * already reach the endpoint; being on the list only means we accept the
         * payload it replays. When PayPay finally points Live at us directly,
         * their own IPs are already here, so nothing needs changing — and a
         * duplicate arriving on both paths is dropped by the unique index on
         * (connection_id, environment, provider_event_id).
         */
        'webhook_source_ips' => [
            'live' => array_values(array_filter(array_merge(
                [
                    '52.68.128.8',
                    '52.192.112.175',
                    '13.115.29.37',
                    '13.208.67.224',
                    '13.208.235.224',
                    '13.208.127.89',
                ],
                array_map('trim', explode(',', (string) env('PAYPAY_WEBHOOK_RELAY_IPS', ''))),
            ))),
            'sandbox' => [
                '13.112.237.64',
                '52.199.148.95',
                '54.199.212.149',
                '13.208.106.122',
                '13.208.115.200',
                '13.208.152.196',
            ],
        ],
    ],

    /*
     * #1784 — đăng nhập khách bằng Google. Hệ RIÊNG, không phải SSO nhân viên.
     *
     * Bỏ trống `client_id` = TẮT: `GoogleIdentityVerifier::enabled()` trả false
     * và endpoint từ chối thẳng. Fail-closed có chủ đích, cùng khuôn
     * `payments.stripe_terminal.enabled` — một tính năng danh tính bật nửa vời
     * còn tệ hơn tắt hẳn.
     *
     * KHÔNG có `client_secret`: luồng dùng là Google Identity Services, trình
     * duyệt lấy ID token rồi backend xác minh chữ ký. Không có bước đổi code nên
     * không có secret nào phải giữ — bớt được một bí mật là bớt một chỗ rò.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
    ],

];
