<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\Brand;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use App\Services\Payment\Policy\PaymentPolicyRevisionPublisher;
use App\Services\Payment\Policy\UnresolvedOwnership;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Support\Logging\MoneyOrchestrationLog;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Ensures the catalog-backed rows a PayPay QR payment needs exist.
 *
 * Same shape as PaymentGatewayOrchestrationBootstrap does for Stripe, and for
 * the same reason: the policy engine cannot resolve in production at all —
 * `BranchManagementProjectionSource` is bound to the Unavailable implementation
 * (fail closed; Platform owns branch management but has not published the
 * grant lifecycle or the read endpoint yet), so
 * every option is denied at the ownership gate before any row is read. Every
 * customer-web Stripe order already runs through that fallback.
 *
 * Four things are deliberately NOT copied from the Stripe bootstrap, each
 * because copying it would break something:
 */
final class PayPayCustomerWebBootstrap
{
    private const OWNERSHIP_REVISION = 'orchestrator-customer-web-v1';

    public function __construct(
        private readonly PaymentPolicyRevisionPublisher $revisionPublisher,
    ) {}

    /**
     * @return array{connectionId: string, connectionOptionId: string, optionId: string, policyRevision: int}
     */
    public function resolveForOrder(OrderRef $order): array
    {
        $this->assertConfigured();

        return DB::transaction(function () use ($order): array {
            $provider = $this->ensureProvider();
            $option = $this->ensureCatalogOption($provider);
            $connection = $this->ensureOrgConnection($order, $provider);
            $connectionOption = $this->ensureConnectionOption($connection, $option);
            $policyRevision = $this->ensurePolicyRevision($order);

            return [
                'connectionId' => (string) $connection->id,
                'connectionOptionId' => (string) $connectionOption->id,
                'optionId' => (string) $option->id,
                'policyRevision' => $policyRevision,
            ];
        }, 5); // Retries: the reads below are not locked, so two first-checkouts race.
    }

    /**
     * The catalog identity alone — provider + capability row, no connection,
     * no policy revision.
     *
     * The shop-level off switch (D9) needs a `PaymentGatewayOption` to point
     * `shop_payment_options.option_id` at, and that row does not exist until
     * the first PayPay checkout runs `resolveForOrder()`: `db:seed` never runs
     * on staging or production, and the catalog migration
     * (PaymentGatewayCatalogSeeder — migration cũ ĐÃ GỠ #2318) deliberately ships only the internal slice. Without
     * this the operator could not opt out BEFORE the first sale — exactly the
     * moment opting out is worth anything.
     *
     * Deliberately does NOT call `assertConfigured()`, unlike every other
     * entry point here. The switch records the shop's intent, not a payment:
     * a shop must be able to say "not here" while the brand is still
     * negotiating credentials, and that intent must already hold on the day
     * the keys land. Catalog rows are release-managed metadata, not money.
     */
    public function ensureCatalogIdentity(): PaymentGatewayOption
    {
        return DB::transaction(fn (): PaymentGatewayOption => $this->ensureCatalogOption($this->ensureProvider()));
    }

    /**
     * Refuses to create anything when PayPay is not configured, so a
     * half-configured deployment cannot end up with rows that advertise a
     * gateway it can never call.
     */
    private function assertConfigured(): void
    {
        foreach (['api_key', 'api_secret', 'merchant_id'] as $key) {
            if (trim((string) config("services.paypay.{$key}")) === '') {
                throw new RuntimeException('PayPay is not configured for this deployment.');
            }
        }
    }

    private function ensureProvider(): PaymentGatewayProvider
    {
        $existing = PaymentGatewayProvider::query()
            ->where('code', PaymentGatewayProviderCodeEnum::Paypay->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentGatewayProvider::query()->create([
            'code' => PaymentGatewayProviderCodeEnum::Paypay->value,
            'is_active' => true,
            'name' => 'PayPay',
            'sort_order' => 20,
        ]);
    }

    private function ensureCatalogOption(PaymentGatewayProvider $provider): PaymentGatewayOption
    {
        $code = PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE;

        $existing = PaymentGatewayOption::query()
            ->where('provider_id', $provider->id)
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Kept in the seeder rather than duplicated here, so there is one
        // definition of the capability and the two cannot drift.
        (new PaymentGatewayCatalogSeeder)->seedPayPayQrOptionFor($provider);

        return PaymentGatewayOption::query()
            ->where('provider_id', $provider->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * (1) The merchant account id is SYNTHETIC and per-org, not the real PayPay
     * merchant id.
     *
     * `payment_gateway_connections` is unique on
     * (provider_id, environment, merchant_account_id) with NO organization_id in
     * the key. Storing the real merchant id — which is global to the deployment —
     * means the second tenant to check out either reuses the first tenant's
     * connection (rejected downstream as "not active for this tenant") or
     * violates the index. Either way: 500 at checkout for everyone but the first.
     *
     * PayPayCredentialsResolver ignores this synthetic reference and falls back
     * to config, mirroring how StripeConnectScope refuses anything that is not a
     * real `acct_` id.
     */
    private function ensureOrgConnection(OrderRef $order, PaymentGatewayProvider $provider): PaymentGatewayConnection
    {
        $environment = $this->resolveEnvironment();
        $merchantAccountId = 'orchestrator:customer-web:'.(string) $order->organizationId;

        // #3075 — khoá tra là NGƯỜI THUÊ, không bao giờ là nhãn merchant ghi vào
        // trong. Đường Stripe từng tra đúng bằng dòng bị xoá ở đây và #3070 nổ
        // ngay lúc migration của #2893 đóng dấu `acct_…` thật lên cột đó: tra
        // trượt, bootstrap đẻ connection THỨ HAI, attempt đi một đường còn
        // provider event ở lại đường kia.
        //
        // Ở PayPay lỗi đó chưa nổ chỉ vì chưa ai đóng dấu định danh merchant
        // thật. Ruling #2893 không nói riêng gì Stripe, nên đây là quả mìn hẹn
        // giờ chứ không phải khác biệt thiết kế.
        //
        // `oldest('created_at')->orderBy('id')` là tất định có chủ đích: nơi nào
        // đã lỡ có hàng trùng, mọi lượt giải phải rơi vào hàng GỐC — hàng đang
        // giữ lịch sử settlement — chứ không phải hàng nào tình cờ ra trước.
        $existing = PaymentGatewayConnection::query()
            ->where('provider_id', $provider->id)
            ->where('environment', $environment->value)
            ->where('organization_id', (string) $order->organizationId)
            ->where('brand_id', (string) $order->brandId)
            ->oldest('created_at')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $brand = Brand::query()->find($order->brandId)
            ?? throw new ModelNotFoundException('Brand not found for order.');

        return PaymentGatewayConnection::query()->create([
            'provider_id' => $provider->id,
            'organization_id' => $order->organizationId,
            'brand_id' => $order->brandId,
            'identity_brand_id' => (string) $brand->id,
            'owner_scope' => 'hq',
            // #3084 — sentinel, KHÔNG phải `Str::uuid()`. Hai cột này là câu trả
            // lời cho "tiền thuộc về ai về mặt pháp lý", và đường customer-web
            // không biết câu đó: nguồn là Identity, mà nguồn đọc còn chưa có.
            // Một UUID ngẫu nhiên ở đây là câu trả lời SAI trông như dữ liệu thật;
            // sentinel nói đúng một câu — chưa phân giải — và tra lại được.
            'brand_owner_org_unit_id' => UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID,
            'operator_org_unit_id' => UnresolvedOwnership::OPERATOR_ORG_UNIT_ID,
            'ownership_revision' => self::OWNERSHIP_REVISION,
            'environment' => $environment->value,
            'merchant_account_id' => $merchantAccountId,
            'merchant_display_name' => 'Customer-web PayPay',
            'charge_model' => 'provider_native',
            'health' => 'ready',
            'is_active' => true,
        ]);
    }

    private function ensureConnectionOption(
        PaymentGatewayConnection $connection,
        PaymentGatewayOption $option,
    ): PaymentGatewayConnectionOption {
        $existing = PaymentGatewayConnectionOption::query()
            ->where('connection_id', $connection->id)
            ->where('option_id', $option->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PaymentGatewayConnectionOption::query()->create([
            'connection_id' => $connection->id,
            'option_id' => $option->id,
            'verification_state' => 'verified',
            // plan-054 T5.5 — MUST NOT be null. `PaymentPolicyResolver` denies
            // with `CapabilityUnverified` when `evidenceReference === null`,
            // *regardless* of `verification_state` — so a row that says
            // "verified" with no reference is internally contradictory: it
            // claims to be usable and can never resolve. PayPay does not reach
            // the policy engine today (`PayPayAvailabilityService` is the gate),
            // which is precisely why this would land as a silent deny on
            // whoever finally routes it through — the debt T5.8 records.
            //
            // The reference names the provisioning source, mirroring the
            // `validation:hq-admin` the HQ /validate path writes. The evidence
            // really is deployment-scoped here: one PayPay merchant contract for
            // the whole deployment, asserted by the credentials in config.
            'evidence_ref' => 'orchestrator:paypay-customer-web',
            // Uppercase deliberately: the policy resolver compares with a strict
            // in_array, so a lowercase code is a silent denial.
            'approved_currencies' => ['JPY'],
            'approved_channels' => ['customer_web'],
            'approved_operations' => ['create', 'retrieve_payment', 'webhook_verification'],
            'capability_revision' => 1,
            'effective_from' => now(),
            'is_enabled' => true,
        ]);
    }

    /**
     * (2) The revision row is PUBLISHED, never hand-written.
     *
     * The Stripe bootstrap writes one directly with `source =>
     * 'orchestrator_bootstrap'`, which is not a PaymentPolicyPublicationSource
     * case. `publishAtomically` validates the newest stored revision before
     * writing a new one, so that row makes every subsequent real publish on the
     * branch throw — permanently disabling the admin payment-settings screens,
     * including the shop-level switch that turns PayPay off.
     *
     * Publishing an empty effective set is honest here: the policy engine cannot
     * resolve, so there is nothing to claim. The authority port only requires
     * that a (branch_id, revision) row exists.
     */
    private function ensurePolicyRevision(OrderRef $order): int
    {
        $existing = PaymentPolicyRevision::query()
            ->where('branch_id', $order->branchId)
            ->orderByDesc('revision')
            ->first();

        if ($existing !== null) {
            return (int) $existing->revision;
        }

        try {
            $published = $this->revisionPublisher->publish(
                new PaymentPolicySnapshotInput(
                    (string) $order->organizationId,
                    (string) $order->brandId,
                    (string) $order->branchId,
                    self::OWNERSHIP_REVISION,
                    hash('sha256', 'paypay-customer-web-bootstrap:'.$order->branchId),
                    [],
                ),
                PaymentPolicyPublicationSource::ConnectionChanged,
            );
        } catch (LogicException|InvalidArgumentException $exception) {
            // Publishing validates that org, brand and branch agree on their
            // console ids and that each id is a real UUID — a stricter bar than
            // the Stripe bootstrap clears, because that one hand-writes its
            // revision and never reaches this code. Tenant data that fails it is
            // a configuration problem, not a customer problem: surface it as
            // "PayPay unavailable" so the guest sees a 422 rather than a 500.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_bootstrap_policy_scope_invalid', [
                'order_id' => (string) $order->orderId,
                'branch_id' => (string) $order->branchId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('PayPay cannot be provisioned for this branch.', previous: $exception);
        }

        return $published->revision;
    }

    /**
     * (3) The environment comes from the credentials held, never from APP_ENV.
     *
     * PayPaySdkClientFactory picks the LIVE PayPay endpoint off this column.
     * Deriving it from `app()->environment('production')` — as the plan first
     * proposed — means a production deploy calls the live API while holding
     * sandbox credentials, which is the normal posture during a pilot. Stripe
     * derives its environment from the secret it actually has, which is the safe
     * direction; PayPay keys carry no live/test marker, so it is explicit.
     */
    private function resolveEnvironment(): PaymentGatewayEnvironmentEnum
    {
        $configured = strtolower(trim((string) config('services.paypay.environment', 'sandbox')));
        $environment = PaymentGatewayEnvironmentEnum::tryFrom($configured) ?? PaymentGatewayEnvironmentEnum::Sandbox;

        if ($environment === PaymentGatewayEnvironmentEnum::Live && ! app()->environment('production')) {
            throw new RuntimeException('PAYPAY_ENVIRONMENT=live is refused outside production.');
        }

        return $environment;
    }
}
