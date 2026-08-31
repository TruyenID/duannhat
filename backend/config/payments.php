<?php

use App\Services\Payment\Gateway\PayPay\PayPayOutageClassifier;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\Sbps\SbpsPaymentGateway;
use App\Services\Payment\Gateway\Stripe\StripeOutageClassifier;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Drivers
    |--------------------------------------------------------------------------
    |
    | Provider adapters are opt-in. Add a provider-code => adapter-class entry
    | only when that runtime adapter is implemented and deployed. The registry
    | never guesses a driver and never falls back to another merchant provider.
    |
    */

    'gateway_drivers' => [
        'paypay' => PayPayPaymentGateway::class,
        'stripe' => StripePaymentGateway::class,

        /*
         * #1796 — SBPS CỐ Ý KHÔNG có ở đây cho tới khi có hợp đồng + IF spec.
         *
         * `SbpsPaymentGateway` tồn tại và từ chối mọi thao tác, nhưng đăng ký nó
         * vào đây thì SAI: `PaymentGatewayRegistry::configuredProviders()` nghĩa
         * là "các provider DÙNG ĐƯỢC", và chính danh sách đó được trích vào
         * thông điệp của `UnsupportedPaymentGatewayProvider`. Thêm `sbps` vào sẽ
         * khiến mọi lỗi provider-lạ khác đi kèm một câu nói dối — rằng SBPS đang
         * dùng được.
         *
         * Bản đầu của #1796 có đăng ký, với lập luận "để thông điệp lỗi tốt hơn".
         * `PaymentGatewayRegistryBindingTest` đỏ và cho thấy lập luận đó ngược:
         * đăng ký làm thông điệp của MỌI lỗi khác tệ đi.
         *
         * Khi hợp đồng về: thêm một dòng `'sbps' => SbpsPaymentGateway::class`,
         * và `SbpsFailsClosedTest` sẽ đỏ ở đúng chỗ nhắc phải cài thân adapter.
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Event Inbox
    |--------------------------------------------------------------------------
    */

    'provider_event' => [
        'max_processing_attempts' => (int) env('PAYMENT_PROVIDER_EVENT_MAX_ATTEMPTS', 5),
        'retry_backoff_seconds' => [10, 30, 60, 120, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-only Gateway Secret Store
    |--------------------------------------------------------------------------
    |
    | This path is non-secret and may be config-cached. The keyring itself must
    | live outside the repository/web root with owner-only permissions. Payment
    | keys are deliberately independent from APP_KEY.
    |
    */

    'secret_store' => [
        'keyring_path' => env('PAYMENT_GATEWAY_KEYRING_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Stripe Refunds Kill-Switch
    |--------------------------------------------------------------------------
    |
    | Master safety gate for the in-app Stripe Refunds API path
    | (OrderPaymentService::refund on a card/Stripe payment). Defaults to
    | FALSE: real card money never moves until an operator explicitly flips
    | STRIPE_LIVE_REFUNDS_ENABLED=true. Cash / non-Stripe refunds are ledger
    | -only and move no external money, so they are NOT affected by this flag.
    |
    */

    'stripe_live_refunds_enabled' => env('STRIPE_LIVE_REFUNDS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum Card Refund Amount (per refund)
    |--------------------------------------------------------------------------
    |
    | Per-refund ceiling for a single Stripe/card refund. A single refund
    | above this value is rejected with a 422 before Stripe is called. The
    | value is currency-minor-unit-agnostic — it is compared directly against
    | the refund amount in the order's own currency. No daily aggregate is
    | tracked; this is intentionally a simple single-refund cap.
    |
    */

    'max_card_refund_amount' => (float) env('MAX_CARD_REFUND_AMOUNT', 1_000_000),

    /*
    |--------------------------------------------------------------------------
    | Concurrent Refunds Per Payment
    |--------------------------------------------------------------------------
    |
    | Maximum number of in-flight PaymentRefund rows allowed per payment
    | attempt while any refund remains in prepared/submitted/pending/
    | reconciliation_required states.
    |
    */

    'max_concurrent_refunds_per_payment' => (int) env('MAX_CONCURRENT_REFUNDS_PER_PAYMENT', 3),

    /*
    |--------------------------------------------------------------------------
    | Orchestrator Runtime (Plan 047 Gate 4 master + Gate 7 per-transport switches)
    |--------------------------------------------------------------------------
    |
    | Gate 4 introduced the master kill switch. Gate 7 (T7.3) adds per-transport
    | toggles so operators can route one transport at a time during cutover.
    |
    | Defaults ON (pre-release): all transports route through PaymentOrchestrator.
    | Set PAYMENT_ORCHESTRATOR_RUNTIME=false to fall back to direct ledger writes.
    |
    | Routing requires ALL of:
    |   1. PAYMENT_ORCHESTRATOR_RUNTIME=true (master switch, default true)
    |   2. Transport listed in PAYMENT_ORCHESTRATOR_TRANSPORTS (allowlist)
    |   3. Matching PAYMENT_ORCHESTRATOR_TRANSPORT_{TRANSPORT}=true (default true)
    |
    | Transports are resolved from orchestrator_transport, metadata.channel, or
    | inferred pos when till_session_id is present. Include customer_web in the
    | allowlist to route Stripe prepare/finalize.
    |
    | When (1) and (2) hold but (3) is false, eligible calls stay on the direct
    | ledger path and emit structured logs on the payment_orchestration channel
    | (orchestrator_legacy_path) for the observation window (T7.4).
    |
    | Rollback: flip master OFF or disable individual transport switches.
    | `payments:observation-report` aggregates drift, shadow backlog, refund
    | pending, and open till sessions for cron/CI gates.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Plan-048 Gate 2 (#1081) — customer-web Stripe connection routing
    |--------------------------------------------------------------------------
    |
    | When true, StripePaymentService resolves the REAL brand connection for
    | every customer-web Stripe call (create/retrieve/cancel/refund) through
    | the payment policy — Connect scope included — instead of the legacy
    | global platform connection. Falls back to legacy automatically when the
    | branch has no policy-backed Stripe option, so behaviour only changes
    | for branches whose real connection has been onboarded.
    |
    | Rollback: PAYMENT_STRIPE_CUSTOMER_WEB_RESOLVED_CONNECTION=false pins
    | every call back to the legacy connection. Removed at T7.6 cleanup.
    |
    */

    'stripe_customer_web_resolved_connection' => (bool) env('PAYMENT_STRIPE_CUSTOMER_WEB_RESOLVED_CONNECTION', true),

    /*
     * plan-055 (#1823) — make the effective-option check MANDATORY.
     *
     * Today the check is opt-in by the client: omit `gateway_option_id` and the
     * policy is never consulted. Flipping this to true refuses such a payment
     * with 422 POLICY_OPTION_REQUIRED instead.
     *
     * ⚠️ Do NOT enable before plan-055 Gate 2 + Gate 3 are satisfied:
     *   - every active branch has a published policy revision AND at least one
     *     effective option (`payments:legacy-removal-readiness`), and
     *   - the offline-replay boundary (T5.1) is resolved — Cloud currently
     *     cannot tell a late-synced offline payment from a live one, so
     *     enabling early refuses money already sitting in the till.
     */
    'policy_enforcement' => [
        'required' => (bool) env('PAYMENT_POLICY_ENFORCEMENT_REQUIRED', false),
    ],

    'orchestrator_runtime' => [
        'enabled' => (bool) env('PAYMENT_ORCHESTRATOR_RUNTIME', true),
        'transports' => array_values(array_filter(array_map(
            static fn (string $transport): string => trim($transport),
            explode(',', (string) env('PAYMENT_ORCHESTRATOR_TRANSPORTS', 'pos,kiosk,workstation,customer_web,self_regi')),
        ))),
        'transport_switches' => [
            'pos' => (bool) env('PAYMENT_ORCHESTRATOR_TRANSPORT_POS', true),
            'kiosk' => (bool) env('PAYMENT_ORCHESTRATOR_TRANSPORT_KIOSK', true),
            'workstation' => (bool) env('PAYMENT_ORCHESTRATOR_TRANSPORT_WORKSTATION', true),
            'customer_web' => (bool) env('PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB', true),
            // #1085 — self-checkout register transport (kiosk surface, own channel).
            'self_regi' => (bool) env('PAYMENT_ORCHESTRATOR_TRANSPORT_SELF_REGI', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Async payment methods (#1125 option B — Konbini / bank transfer)
    |--------------------------------------------------------------------------
    |
    | OFF (default): browser PaymentIntents are created card-only (the #1125
    | option-A hotfix stays the shipped posture). ON: intents use Stripe
    | dynamic payment methods (automatic_payment_methods, redirects excluded)
    | so the Dashboard's enabled methods — e.g. Konbini, 銀行振込 — appear in
    | the Payment Element. The async lifecycle (processing / late succeeded /
    | payment_failed / canceled webhooks, pending ledger rows, expiry
    | reconciliation) is ALWAYS armed regardless of this flag — the flag only
    | opens the door at intent creation.
    |
    | konbini_expires_after_days bounds the voucher (Stripe: 0–60). Keep it
    | at or under the takeaway payment window so the reaper and the voucher
    | can't disagree about who expires first.
    */

    'async_payment_methods' => [
        'enabled' => (bool) env('PAYMENT_ASYNC_METHODS_ENABLED', false),
        'konbini_expires_after_days' => (int) env('PAYMENT_KONBINI_EXPIRES_AFTER_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Terminal — server-driven card_present (#1088)
    |--------------------------------------------------------------------------
    |
    | OFF (default): the stripe.terminal.card_present.v1 catalog capability
    | stays Disabled and every Terminal endpoint fails closed — matching the
    | "certification required" posture in plans/plan-047/CAPABILITIES.md.
    | ON: readers registered per branch (PeripheralDevice payment_terminal
    | rows with metadata.provider = stripe_terminal) accept server-driven
    | charges: Cloud creates a card_present PaymentIntent, hands it to the
    | reader, ledgers an awaiting-async PENDING row, and the ordinary
    | payment_intent.succeeded webhook settles it (#1125 lifecycle).
    |
    | location_country seeds Terminal Location rows when a branch has no
    | structured address (Stripe requires country + line1).
    */

    'stripe_terminal' => [
        'enabled' => (bool) env('PAYMENT_STRIPE_TERMINAL_ENABLED', false),
        'location_country' => env('PAYMENT_STRIPE_TERMINAL_COUNTRY', 'JP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PayPay dynamic QR — stale attempt sweep (plan-054, R13)
    |--------------------------------------------------------------------------
    |
    | The webhook receiver is descoped for the pilot, so the customer's own
    | status poll is the only thing that moves a QR attempt out of its open
    | state. Closing the tab — or never scanning, which is the ordinary
    | outcome — leaves the attempt open forever: clutter at best, and at worst
    | a payment made in the second before the tab closed that nothing in this
    | system ever notices. `payments:sweep-paypay-qr` asks PayPay about those.
    |
    | grace_minutes — how long past the mint before an unscanned `CREATED`
    | code may be *retired*. It does NOT delay asking PayPay about live
    | attempts (#2445): COMPLETED money is booked on every tick regardless of
    | age. PayPay never reports expiry on the code endpoint — it answers
    | `CREATED` indefinitely for a code nobody scanned — so retirement
    | concludes from age alone. That is safe only while this stays comfortably
    | larger than PayPayQrCodeClient::CODE_LIFETIME_MINUTES (~5, PayPay-
    | controlled and not settable) — the default 15 is a 3x margin. Below 2x
    | the command refuses to retire unscanned codes at all and logs
    | `paypay_qr_sweep_grace_too_short`, because at that point "old enough to
    | be dead" stops being a fact and a customer could still be mid-scan. If
    | PayPay ever LENGTHENS the code life, raise this with it: the margin, not
    | the constant, is what makes the inference sound.
    |
    | batch_limit — attempts examined per run. Each one costs a PayPay round
    | trip, so the cap keeps a backlog from turning one tick into a long
    | serial crawl; the remainder is picked up on the next tick.
    */

    'paypay_qr' => [
        'stale_sweep_grace_minutes' => (int) env('PAYPAY_QR_STALE_SWEEP_GRACE_MINUTES', 15),
        'stale_sweep_batch_limit' => (int) env('PAYPAY_QR_STALE_SWEEP_BATCH_LIMIT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Settlement & Payout Reconciliation (plan-050, #1155)
    |--------------------------------------------------------------------------
    |
    | reconcile_after_days — direction A window per provider: a succeeded
    | gateway payment older than this with NO payment_settlements row is
    | reported by `settlements:reconcile` ("gateway never reported this
    | money" — also detects a missing webhook subscription, G6). Providers
    | NOT listed here are exempt from settlement reconciliation entirely
    | (`internal` cash tenders have no gateway statement).
    |
    | aging_alert_days — pending_payout rows older than this flag the
    | connection as over-threshold in the aging report. Per provider by
    | design: Stripe pays out in days, PayPay in monthly cycles + buffer
    | (G4 — thresholds are configuration, never hardcoded).
    |
    | aging_buckets — upper-inclusive day edges for the aging report
    | buckets; a final open-ended bucket is appended automatically.
    |
    */

    /*
     * #2937 — ai được báo khi đối soát tiền mặt ra lệch CÓ HÀNH ĐỘNG.
     *
     * Là CẤU HÌNH chứ không hardcode: ai chịu trách nhiệm tiền mặt khác nhau
     * theo tổ chức. Mặc định `shop-manager` — lệch két là việc của quán trước,
     * khác `settlement.alert_role` (mặc định `org-admin`) vốn là tiền ở cổng.
     *
     * ⚠️ Từ vựng thật là `RoleTemplateMatrix::ROLES`, TOÀN GẠCH NGANG. Slug sai
     * KHÔNG ném lỗi — nó phân giải ra 0 người nhận và im lặng mãi mãi (#2451,
     * #2456 đã dẫm bốn lần).
     */
    'cash_drawer' => [
        'alert_role' => env('CASH_DRAWER_ALERT_ROLE', 'shop-manager'),
    ],

    'settlement' => [
        'reconcile_after_days' => [
            'stripe' => (int) env('SETTLEMENT_RECONCILE_AFTER_DAYS_STRIPE', 7),
            'paypay' => (int) env('SETTLEMENT_RECONCILE_AFTER_DAYS_PAYPAY', 45),
        ],
        'aging_alert_days' => [
            'stripe' => (int) env('SETTLEMENT_AGING_ALERT_DAYS_STRIPE', 7),
            'paypay' => (int) env('SETTLEMENT_AGING_ALERT_DAYS_PAYPAY', 45),
        ],
        'aging_buckets' => [3, 7, 14, 30],

        // T4.3 — ai nhận cảnh báo đối soát. CẤU HÌNH, không hardcode: người
        // chịu trách nhiệm đối soát tiền khác nhau theo tổ chức, và đó là
        // quyết định vận hành chứ không phải hằng số kỹ thuật.
        'alert_role' => env('SETTLEMENT_ALERT_ROLE', 'org-admin'),

        // Bật/tắt việc GỬI cảnh báo. Tắt thì `settlements:reconcile` vẫn đối
        // soát và vẫn in ra đầy đủ — chỉ không gửi thông báo. Dùng khi đang
        // dựng dữ liệu ban đầu, giai đoạn mà mọi dòng đều là orphan.
        'alerts_enabled' => (bool) env('SETTLEMENT_ALERTS_ENABLED', true),

        // T2.4 (#1978) — event mà webhook endpoint của một gateway PHẢI đăng
        // ký để tầng đối soát nhìn thấy đủ tiền. Danh sách chốt ở
        // docs/guide/gateway-settlement.md ("Webhook checklist per Stripe
        // connection") và được `settlements:audit-webhooks` đối chiếu với
        // những gì thực sự đăng ký ở gateway.
        //
        // Mục kết thúc bằng `.*` là một HỌ event: Stripe chỉ nhận tên cụ thể
        // hoặc `*` trong `enabled_events`, nên yêu cầu `charge.dispute.*`
        // được coi là đã phủ khi endpoint đăng ký BẤT KỲ event nào bắt đầu
        // bằng `charge.dispute.`.
        'required_webhook_events' => [
            'stripe' => [
                'payment_intent.succeeded',
                'charge.refunded',
                'charge.dispute.*',
                'payout.paid',
                'payout.failed',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Adapter Circuit Breaker (plan-048 T7.5 / #1105 — J1)
    |--------------------------------------------------------------------------
    |
    | Per (provider, connection) breaker over registry-resolved adapters.
    | DISABLED BY DEFAULT — the #1105 deferral decision stands for production:
    | tune failure_threshold / cooldown against real Gate-7 provider error
    | rates before enabling. Only provider OUTAGES (transport errors, provider
    | 5xx) count toward the threshold; declines and mapped business errors
    | never trip it. While open, new payment creates are refused with
    | PAYMENT_PROVIDER_CIRCUIT_OPEN (no PaymentAttempt is reserved); recovery
    | operations (capture/refund/retrieve) always pass through.
    |
    */

    'circuit_breaker' => [
        'enabled' => (bool) env('PAYMENTS_CIRCUIT_BREAKER_ENABLED', false),
        'failure_threshold' => (int) env('PAYMENTS_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'failure_window_seconds' => (int) env('PAYMENTS_CIRCUIT_BREAKER_FAILURE_WINDOW', 120),
        'cooldown_seconds' => (int) env('PAYMENTS_CIRCUIT_BREAKER_COOLDOWN', 60),
        'probe_ttl_seconds' => (int) env('PAYMENTS_CIRCUIT_BREAKER_PROBE_TTL', 30),
        // SDK-aware outage classification lives beside each adapter, keeping
        // the neutral gateway boundary SDK-free.
        'outage_classifiers' => [
            StripeOutageClassifier::class,
            PayPayOutageClassifier::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Money reconciliation outbox (#1204 / #1206)
    |--------------------------------------------------------------------------
    |
    | Tuning for `payments:redrive-reconciliation`. Defaults mirror the VN
    | e-invoice queue (#1153) so the two behave alike under load.
    |
    | stale_pending_minutes is the one that matters operationally: for
    | stranded_charge and overpayment_rejected the relay does not settle the
    | money on its own, so AGE is the alert.
    |
    */
    'reconciliation' => [
        'claim_timeout_minutes' => (int) env('RECONCILIATION_CLAIM_TIMEOUT_MINUTES', 15),
        'max_attempts' => (int) env('RECONCILIATION_MAX_ATTEMPTS', 20),
        'stale_pending_minutes' => (int) env('RECONCILIATION_STALE_PENDING_MINUTES', 120),
    ],

];
