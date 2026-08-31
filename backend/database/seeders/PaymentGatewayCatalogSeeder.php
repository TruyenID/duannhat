<?php

namespace Database\Seeders;

use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use Illuminate\Database\Seeder;

/**
 * Seeds the release-managed payment gateway provider + capability catalog.
 *
 * Evidence: plans/plan-047/CAPABILITIES.md baseline matrix (2026-07-22).
 * Does not create merchant connections or store credentials.
 */
final class PaymentGatewayCatalogSeeder extends Seeder
{
    use Concerns\WritesTranslations;

    public const STRIPE_OPTION_CODE = 'stripe.pi.card.web.v1';

    public const PAYPAY_OPTION_CODE = 'paypay.preauth.wallet.v1';

    /**
     * Dynamic-QR (OPA Web Payment) capability — plan-054.
     *
     * A separate capability rather than a variant of PAYPAY_OPTION_CODE because
     * it is a different workflow: preauth needs a `userAuthorizationId` that only
     * exists once the customer has linked their PayPay account to the merchant,
     * which a guest takeaway checkout never does (the adapter throws without it).
     * The QR flow asks the customer for nothing — they scan and pay — so it is
     * the only PayPay capability that can serve customer-web today.
     *
     * Refund is deliberately absent from `operations`: no PayPay refund path is
     * wired yet, and declaring one the code cannot honour is worse than
     * declaring none (plan-054 D5 blocks refunds on PayPay rows with a 409).
     */
    public const PAYPAY_QR_OPTION_CODE = 'paypay.web_payment.qr.v1';

    public const INTERNAL_CASH_OPTION_CODE = 'internal.cash.v1';

    public const INTERNAL_CARD_TERMINAL_OPTION_CODE = 'internal.card_terminal.v1';

    /**
     * `payment_gateway_options` declares these six json columns NOT NULL with
     * no DB-level default, so any INSERT must provide them. `firstOrCreate()`
     * inserts using only the attributes handed to it, and the seeders assign
     * the real catalog values afterwards via `fill()` + `save()` — leaving the
     * INSERT itself short and failing with:
     *
     *     SQLSTATE[HY000]: 1364 Field 'brands' doesn't have a default value
     *
     * Empty arrays here just get the row created; the immediately-following
     * save() overwrites every one of them with the real values.
     *
     * @var array<string, list<mixed>>
     */
    private const REQUIRED_JSON_DEFAULTS = [
        'brands' => [],
        'channels' => [],
        'device_classes' => [],
        'currencies' => [],
        'workflows' => [],
        'operations' => [],
    ];

    public function run(): void
    {
        $this->seedInternal();

        $stripe = $this->seedProvider(
            PaymentGatewayProviderCodeEnum::Stripe,
            'Stripe',
            'Stripe card PaymentIntents for customer web checkout.',
            10,
        );

        $paypay = $this->seedProvider(
            PaymentGatewayProviderCodeEnum::Paypay,
            'PayPay',
            'PayPay OPA PreAuth & Capture wallet flow (JPY).',
            20,
        );

        $this->seedStripeOption($stripe);
        $this->seedPayPayOption($paypay);
        $this->seedPayPayQrOptionFor($paypay);
    }

    /**
     * Internal-ledger slice only — callable from the data migration
     * (ĐÃ GỠ #2318 — nguồn duy nhất giờ là seeder này) without dragging Stripe/PayPay provider rows into
     * every migrated database: many gateway tests seed those providers
     * themselves and would collide on the unique `code` index.
     */
    public function seedInternal(): void
    {
        $internal = $this->seedProvider(
            PaymentGatewayProviderCodeEnum::Internal,
            'Internal ledger',
            'Ledger-only internal tenders (cash, standalone card terminal); no provider API.',
            5,
        );

        $this->seedInternalCashOption($internal);
        $this->seedInternalCardTerminalOption($internal);
    }

    /**
     * Internal tenders never call a gateway (invariant: cash/card_terminal go
     * through recordTender, never a provider API), so these options carry no
     * connection, no webhook, and no recovery operations — the catalog rows
     * exist to give internal tenders a stable policy/catalog identity
     * (plan-048 T1.1, CAPABILITIES baseline "Internal ledger" row).
     */
    private function seedInternalCashOption(PaymentGatewayProvider $provider): void
    {
        $option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'code' => self::INTERNAL_CASH_OPTION_CODE,
            ],
            [
                'integration_product' => 'internal_ledger',
                'api_version' => 'v1',
                'rail' => 'cash',
                'method_type' => 'cash',
                'revision' => 1,
                'effective_from' => '2026-07-26 00:00:00',
                'is_active' => true,
                'sort_order' => 1,
                // See seedStripeOption(): satisfies the NOT NULL json columns
                // on INSERT; real values land in the fill()/save() below.
                ...self::REQUIRED_JSON_DEFAULTS,
            ],
        );

        $this->writeTranslations($option, [
            'en' => ['name' => 'Cash (internal ledger)'],
            'ja' => ['name' => '現金（内部台帳）'],
            'vi' => ['name' => 'Tiền mặt (sổ nội bộ)'],
        ]);
        $option->fill([
            'integration_product' => 'internal_ledger',
            'api_version' => 'v1',
            'rail' => 'cash',
            'method_type' => 'cash',
            'brands' => ['internal'],
            // #1085 — self_regi collects cash by default (釣銭機 point).
            'channels' => ['pos', 'kiosk', 'workstation', 'self_regi'],
            'device_classes' => ['terminal', 'kiosk', 'workstation'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
                ['code' => 'USD', 'minor_unit' => 2],
                ['code' => 'VND', 'minor_unit' => 0],
            ],
            'workflows' => ['sale'],
            'operations' => ['create', 'refund'],
            'limits' => [
                'partial_capture' => false,
                'partial_refund' => true,
                'multiple_refunds' => true,
                'min_amount' => 1,
                'max_amount' => 999_999_999,
            ],
            'recovery' => [
                'retrieve_payment' => false,
                'retrieve_refund' => false,
                'webhook_verification' => false,
                'strategy' => 'ledger_only',
            ],
            'merchant_identity_requirements' => null,
            'revision' => 1,
            'effective_from' => '2026-07-26 00:00:00',
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $option->save();
    }

    /**
     * Standalone bank terminal: approval happens out-of-band on the device, so
     * the ledger only records the sale — same recordTender path as cash, no
     * kiosk channel (the terminal is staff-operated).
     */
    private function seedInternalCardTerminalOption(PaymentGatewayProvider $provider): void
    {
        $option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'code' => self::INTERNAL_CARD_TERMINAL_OPTION_CODE,
            ],
            [
                'integration_product' => 'internal_ledger',
                'api_version' => 'v1',
                'rail' => 'card',
                'method_type' => 'card_terminal',
                'revision' => 1,
                'effective_from' => '2026-07-26 00:00:00',
                'is_active' => true,
                'sort_order' => 2,
                // See seedStripeOption(): satisfies the NOT NULL json columns
                // on INSERT; real values land in the fill()/save() below.
                ...self::REQUIRED_JSON_DEFAULTS,
            ],
        );

        $this->writeTranslations($option, [
            'en' => ['name' => 'Card terminal (internal ledger)'],
            'ja' => ['name' => 'カード端末（内部台帳）'],
            'vi' => ['name' => 'Máy quẹt thẻ (sổ nội bộ)'],
        ]);
        $option->fill([
            'integration_product' => 'internal_ledger',
            'api_version' => 'v1',
            'rail' => 'card',
            'method_type' => 'card_terminal',
            'brands' => ['internal'],
            // #1085 — card_present on by default for the self-checkout register.
            'channels' => ['pos', 'workstation', 'self_regi'],
            'device_classes' => ['terminal', 'workstation'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
                ['code' => 'USD', 'minor_unit' => 2],
                ['code' => 'VND', 'minor_unit' => 0],
            ],
            'workflows' => ['sale'],
            'operations' => ['create', 'refund'],
            'limits' => [
                'partial_capture' => false,
                'partial_refund' => true,
                'multiple_refunds' => true,
                'min_amount' => 1,
                'max_amount' => 999_999_999,
            ],
            'recovery' => [
                'retrieve_payment' => false,
                'retrieve_refund' => false,
                'webhook_verification' => false,
                'strategy' => 'ledger_only',
            ],
            'merchant_identity_requirements' => null,
            'revision' => 1,
            'effective_from' => '2026-07-26 00:00:00',
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $option->save();
    }

    private function seedProvider(
        PaymentGatewayProviderCodeEnum $code,
        string $nameEn,
        string $descriptionEn,
        int $sortOrder,
    ): PaymentGatewayProvider {
        $provider = PaymentGatewayProvider::query()->firstOrCreate(
            ['code' => $code->value],
            [
                'is_active' => true,
                'sort_order' => $sortOrder,
            ],
        );

        $this->writeTranslations($provider, [
            'en' => ['name' => $nameEn, 'description' => $descriptionEn],
            'ja' => ['name' => $nameEn, 'description' => $descriptionEn],
            'vi' => ['name' => $nameEn, 'description' => $descriptionEn],
        ]);
        $provider->is_active = true;
        $provider->sort_order = $sortOrder;
        $provider->save();

        return $provider;
    }

    private function seedStripeOption(PaymentGatewayProvider $provider): void
    {
        $option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'code' => self::STRIPE_OPTION_CODE,
            ],
            [
                'integration_product' => 'payment_intents',
                'api_version' => '2026-06-30',
                'rail' => 'card',
                'method_type' => 'card',
                'revision' => 1,
                'effective_from' => '2026-07-22 00:00:00',
                'is_active' => true,
                'sort_order' => 10,
                // The six NOT NULL json columns have no DB default, so the
                // INSERT firstOrCreate() performs must supply them. The real
                // values are written by the fill()/save() below; these
                // placeholders only get the row created.
                ...self::REQUIRED_JSON_DEFAULTS,
            ],
        );

        $this->writeTranslations($option, [
            'en' => ['name' => 'Stripe card (customer web)'],
            'ja' => ['name' => 'Stripeカード（顧客Web）'],
            'vi' => ['name' => 'Stripe thẻ (web khách)'],
        ]);
        $option->fill([
            'integration_product' => 'payment_intents',
            'api_version' => '2026-06-30',
            'rail' => 'card',
            'method_type' => 'card',
            'brands' => ['account_configured'],
            'channels' => ['customer_web'],
            'device_classes' => ['browser'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
                ['code' => 'USD', 'minor_unit' => 2],
                ['code' => 'VND', 'minor_unit' => 0],
            ],
            'workflows' => ['sale', 'authorize_capture'],
            'operations' => [
                'create',
                'retrieve_payment',
                'capture',
                'cancel',
                'refund',
                'retrieve_refund',
                'webhook_verification',
            ],
            'limits' => [
                'partial_capture' => true,
                'partial_refund' => true,
                'multiple_refunds' => true,
                'min_amount' => 1,
                'max_amount' => 999_999_999,
                'authorization_ttl' => 604800,
                'capture_ttl' => 86400,
                'refund_ttl' => 2592000,
            ],
            'recovery' => [
                'retrieve_payment' => true,
                'retrieve_refund' => true,
                'webhook_verification' => true,
                'strategy' => 'webhook_and_retrieve',
            ],
            'merchant_identity_requirements' => ['connected_account'],
            'revision' => 1,
            'effective_from' => '2026-07-22 00:00:00',
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $option->save();
    }

    /**
     * plan-054 — PayPay dynamic QR (OPA Web Payment, `/v2/codes`).
     *
     * Guest-payable: the customer scans a merchant-presented code, so unlike
     * seedPayPayOption() there is no account-linking prerequisite. Capture and
     * cancel are absent because a QR payment is terminal at COMPLETED — there is
     * no separate capture step to authorise.
     */
    public function seedPayPayQrOptionFor(PaymentGatewayProvider $provider): void
    {
        $option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'code' => self::PAYPAY_QR_OPTION_CODE,
            ],
            [
                'integration_product' => 'opa_web_payment',
                'api_version' => '2.0',
                'rail' => 'wallet',
                'method_type' => 'paypay',
                'revision' => 1,
                'effective_from' => '2026-07-29 00:00:00',
                'is_active' => true,
                'sort_order' => 21,
                // See seedStripeOption(): satisfies the NOT NULL json columns
                // on INSERT; real values land in the fill()/save() below.
                ...self::REQUIRED_JSON_DEFAULTS,
            ],
        );

        $this->writeTranslations($option, [
            'en' => ['name' => 'PayPay QR (web payment)'],
            'ja' => ['name' => 'PayPay QR（ウェブ決済）'],
            'vi' => ['name' => 'PayPay QR (thanh toán web)'],
        ]);
        $option->fill([
            'integration_product' => 'opa_web_payment',
            'api_version' => '2.0',
            'rail' => 'wallet',
            'method_type' => 'paypay',
            'brands' => ['paypay'],
            'channels' => ['customer_web'],
            'device_classes' => ['browser'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
            ],
            'workflows' => ['sale'],
            // No `capture`/`cancel`: a QR payment settles in one step. No
            // `refund`/`retrieve_refund`: see the PAYPAY_QR_OPTION_CODE docblock.
            'operations' => [
                'create',
                'retrieve_payment',
                'webhook_verification',
            ],
            'limits' => [
                'partial_capture' => false,
                'partial_refund' => false,
                'multiple_refunds' => false,
                'min_amount' => 1,
                'max_amount' => 10_000_000,
            ],
            'recovery' => [
                'retrieve_payment' => true,
                'retrieve_refund' => false,
                'webhook_verification' => true,
                'strategy' => 'daily_reconciliation',
            ],
            // Web payment has no physical store/terminal, unlike the preauth
            // capability which snapshots store_id + terminal_id.
            'merchant_identity_requirements' => ['assume_merchant'],
            'revision' => 1,
            'effective_from' => '2026-07-29 00:00:00',
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 21,
        ]);
        $option->save();
    }

    private function seedPayPayOption(PaymentGatewayProvider $provider): void
    {
        $option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'code' => self::PAYPAY_OPTION_CODE,
            ],
            [
                'integration_product' => 'opa_preauth_capture',
                'api_version' => '1.0',
                'rail' => 'wallet',
                'method_type' => 'paypay',
                'revision' => 1,
                'effective_from' => '2026-07-22 00:00:00',
                'is_active' => true,
                'sort_order' => 20,
                // See seedStripeOption(): satisfies the NOT NULL json columns
                // on INSERT; real values land in the fill()/save() below.
                ...self::REQUIRED_JSON_DEFAULTS,
            ],
        );

        $this->writeTranslations($option, [
            'en' => ['name' => 'PayPay wallet (OPA preauth)'],
            'ja' => ['name' => 'PayPayウォレット（OPA事前承認）'],
            'vi' => ['name' => 'PayPay ví (OPA preauth)'],
        ]);
        $option->fill([
            'integration_product' => 'opa_preauth_capture',
            'api_version' => '1.0',
            'rail' => 'wallet',
            'method_type' => 'paypay',
            'brands' => ['paypay'],
            'channels' => ['customer_web'],
            'device_classes' => ['browser'],
            'currencies' => [
                ['code' => 'JPY', 'minor_unit' => 0],
            ],
            'workflows' => ['authorize_capture'],
            'operations' => [
                'create',
                'retrieve_payment',
                'capture',
                'cancel',
                'refund',
                'retrieve_refund',
                'webhook_verification',
            ],
            'limits' => [
                'partial_capture' => true,
                'partial_refund' => false,
                'multiple_refunds' => true,
                'min_amount' => 1,
                'max_amount' => 10_000_000,
                'authorization_ttl' => 30,
                'capture_ttl' => 86400,
                'refund_ttl' => 2592000,
            ],
            'recovery' => [
                'retrieve_payment' => true,
                'retrieve_refund' => true,
                'webhook_verification' => true,
                'strategy' => 'daily_reconciliation',
            ],
            'merchant_identity_requirements' => ['assume_merchant', 'store_id', 'terminal_id'],
            'revision' => 1,
            'effective_from' => '2026-07-22 00:00:00',
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $option->save();
    }
}
