<?php

namespace App\Services\Payment\Orchestration;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Orchestration\Commands\AttachCustomerWebPrepareReferenceCommand;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Commands\RecordPaymentTenderCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\Orchestration\Contracts\PaymentPersistencePort;
use App\Services\Payment\Orchestration\Enums\TenderKind;
use App\Services\Payment\Orchestration\Internal\CustomerWebStripeConnectionResolver;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use App\Services\Payment\Orchestration\Internal\PaymentGatewayOrchestrationBootstrap;
use App\Services\Payment\Orchestration\Internal\StripeCanonicalPaymentMethodProvisioner;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationMetrics;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Orchestration\ValueObjects\PaymentTenderPayload;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stripe\PaymentIntent;

/** Gate 4 bridge from OrderPaymentService payloads to PaymentOrchestrator. */
final class OrderPaymentOrchestrationCompat
{
    private const STRIPE_SYSTEM_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly PaymentMutationFacade $payments,
        private readonly PaymentPersistencePort $persistence,
        private readonly OrderPaymentLedgerWriter $ledger,
        private readonly PaymentGatewayOrchestrationBootstrap $gatewayBootstrap,
        private readonly StripeCanonicalPaymentMethodProvisioner $stripeMethods,
        private readonly CustomerWebStripeConnectionResolver $stripeConnectionResolver,
    ) {}

    public function enabledForTransport(?string $transport, ?string $operation = null): bool
    {
        if (! (bool) config('payments.orchestrator_runtime.enabled', false)) {
            return false;
        }

        if ($transport === null) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('payments.orchestrator_runtime.transports', []);

        if (! in_array('*', $allowed, true) && ! in_array($transport, $allowed, true)) {
            return false;
        }

        /** @var array<string, bool> $switches */
        $switches = config('payments.orchestrator_runtime.transport_switches', []);

        if (! ($switches[$transport] ?? false)) {
            if ($operation !== null) {
                PaymentOrchestrationMetrics::incrementLegacyPath($transport, $operation);
            }

            return false;
        }

        return true;
    }

    public function resolveTransportFromPayment(OrderPayment $payment): ?string
    {
        // The server-owned column is authoritative. Fall back to the legacy
        // metadata.channel only for rows written before the column existed
        // (#1058/#1059), then to the till-session heuristic.
        if (is_string($payment->channel) && $payment->channel !== '') {
            return $payment->channel;
        }

        $metadata = $payment->metadata ?? [];
        if (is_array($metadata) && isset($metadata['channel']) && is_string($metadata['channel'])) {
            return $metadata['channel'];
        }

        if (! empty($payment->till_session_id)) {
            return 'pos';
        }

        return null;
    }

    public function shouldRouteLegacyConfirm(OrderPayment $payment): bool
    {
        if ($payment->payment_attempt_id === null) {
            return false;
        }

        return $this->enabledForTransport($this->resolveTransportFromPayment($payment), 'legacy_confirm');
    }

    public function shouldRouteLegacyFail(OrderPayment $payment): bool
    {
        return $this->shouldRouteLegacyConfirm($payment);
    }

    public function shouldRouteLegacyRefund(OrderPayment $payment): bool
    {
        if ($payment->payment_attempt_id === null) {
            return false;
        }

        return $this->enabledForTransport($this->resolveTransportFromPayment($payment), 'legacy_refund');
    }

    public function resolveTransport(array $data): ?string
    {
        if (isset($data['orchestrator_transport']) && is_string($data['orchestrator_transport'])) {
            return $data['orchestrator_transport'];
        }

        $metadata = $data['metadata'] ?? null;
        if (is_array($metadata) && isset($metadata['channel']) && is_string($metadata['channel'])) {
            return $metadata['channel'];
        }

        if (! empty($data['till_session_id'])) {
            return 'pos';
        }

        return null;
    }

    public function shouldRouteAutoConfirmCreate(array $data, PaymentMethod $method): bool
    {
        if (! $method->is_auto_confirm || ! empty($data['refund_of_id'])) {
            return false;
        }

        if ($this->isStripeCanonicalMethod($method)) {
            return $this->shouldRouteCustomerWebStripe();
        }

        if (! empty($data['reference_no'])) {
            return false;
        }

        return $this->enabledForTransport($this->resolveTransport($data), 'auto_confirm_create');
    }

    public function shouldRouteCustomerWebStripe(): bool
    {
        return $this->enabledForTransport(PaymentChannelEnum::CustomerWeb->value, 'customer_web_stripe');
    }

    public function isStripeCanonicalMethod(PaymentMethod $method): bool
    {
        return (string) $method->code === 'stripe';
    }

    public function shouldRouteStripePrepare(): bool
    {
        return $this->shouldRouteCustomerWebStripe();
    }

    public function findStripeAttemptForIntent(string $paymentIntentId): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('provider_object_id', $paymentIntentId)
            ->where('channel', PaymentChannelEnum::CustomerWeb->value)
            ->first();
    }

    /**
     * Reserve a durable PaymentAttempt before or immediately after Stripe intent creation.
     *
     * Idempotent on payment intent id — replays return the existing attempt id.
     *
     * #1594 — CÒN nhận model, khác bốn mắt PayPay ngay dưới. Không phải bỏ sót:
     * chỗ gọi duy nhất là `StripePaymentService`, và class đó đang bị #1611 giữ
     * (`customer_orders.stripe_payment_intent_id` đặt sai bảng). Chuyển mắt này
     * mà không chuyển chỗ gọi chỉ DỜI điểm chuyển đổi lên một bậc.
     */
    public function prepareStripePaymentIntent(
        CustomerOrder $order,
        string $paymentIntentId,
        int $amountMinor,
        string $currency,
        string $flow,
    ): ?string {
        if (! $this->shouldRouteStripePrepare()) {
            return null;
        }

        $existing = $this->findStripeAttemptForIntent($paymentIntentId);
        if ($existing !== null) {
            return (string) $existing->id;
        }

        $refs = $this->stripeConnectionResolver->resolveForOrder(new OrderRef((string) $order->id, (string) $order->organization_id, $order->brand_id === null ? null : (string) $order->brand_id, (string) $order->branch_id));
        $stripeMethod = $this->stripeMethods->resolveForOrganization((string) $order->organization_id);

        $attemptId = (string) Str::uuid();
        $context = new MutationContext(
            (string) $order->organization_id,
            self::STRIPE_SYSTEM_ACTOR_ID,
            (string) Str::uuid(),
            'stripe-prepare:'.$paymentIntentId,
            expectedVersion: 1,
        );

        $expectedTotalMinor = $this->toMinorUnits((float) $order->total_amount, $currency);
        $tender = new PaymentTenderPayload(
            (string) $stripeMethod->id,
            TenderKind::Card,
            reference: $paymentIntentId,
        );

        $result = $this->payments->prepare(new PreparePaymentCommand(
            $context,
            $attemptId,
            (string) $order->id,
            (string) $order->branch_id,
            $refs['connectionId'],
            $refs['optionId'],
            $amountMinor,
            $currency,
            $refs['policyRevision'],
            $expectedTotalMinor,
            $expectedTotalMinor,
            $tender,
            authorizationReference: 'stripe-prepare:'.$flow,
        ));

        $persistedAttemptId = $result->attemptId;

        $this->persistence->attachCustomerWebPrepareReference(new AttachCustomerWebPrepareReferenceCommand(
            $context,
            $persistedAttemptId,
            $paymentIntentId,
        ));

        return $persistedAttemptId;
    }

    /**
     * plan-054 — reserve the attempt a PayPay QR will be minted against.
     *
     * Called BEFORE the QR exists, unlike the Stripe twin above which already
     * holds an intent id. That ordering is the point: the attempt mints the
     * merchant payment id, so a QR can never be live at PayPay without a row to
     * match its webhook against. The reverse order leaves an orphan QR whose
     * payment lands as `paypay_no_matching_attempt` — an event marked succeeded
     * while the money is never ledgered.
     *
     * Returns null ONLY if the caller must abort: unlike Stripe, PayPay cannot
     * fall back to keying its ledger write on a provider id, so a missing
     * attempt is fatal rather than degraded.
     *
     * #1594 — ba mắt PayPay còn lại (`abandon` · `retire` · `settle`) giờ cũng
     * nhận ảnh chụp. Cả ba đọc đúng MỘT vô hướng — `organization_id`, cho
     * `MutationContext` — nên nhận model là trả giá cả một cạnh module cho một
     * cột.
     */
    public function preparePayPayQrAttempt(
        // #1603 — ẢNH CHỤP, không phải model. Đo được: method này chỉ đọc bốn
        // vô hướng (`id`, `organization_id`, `branch_id`, `total_amount`), và cả
        // bốn đã có sẵn trên hợp đồng Ordering công bố. Nhận model ở đây là giữ
        // một cạnh Payments → Ordering mà không dùng tới gì của model.
        OrderSnapshot $order,
        string $connectionId,
        // The CATALOG option id, not the connection_option row id. The authority
        // port looks this up as
        // PaymentGatewayConnectionOption::where('connection_id', …)->where('option_id', …),
        // so handing it the row id finds nothing and refuses the preparation.
        string $optionId,
        int $policyRevision,
        int $amountMinor,
        string $currency,
        string $paymentMethodId,
    ): ?array {
        if (! $this->enabledForTransport('customer_web')) {
            return null;
        }

        $attemptId = (string) Str::uuid();
        $merchantPaymentId = PayPayQrCodeClient::merchantPaymentIdFor($attemptId);

        $context = new MutationContext(
            $order->organizationId(),
            self::STRIPE_SYSTEM_ACTOR_ID,
            (string) Str::uuid(),
            'paypay-qr-prepare:'.$merchantPaymentId,
            expectedVersion: 1,
        );

        $expectedTotalMinor = $this->toMinorUnits($order->totalAmount(), $currency);

        $result = $this->payments->prepare(new PreparePaymentCommand(
            $context,
            $attemptId,
            $order->aggregateId(),
            $order->branchId(),
            $connectionId,
            $optionId,
            $amountMinor,
            $currency,
            $policyRevision,
            $expectedTotalMinor,
            $expectedTotalMinor,
            // Kind must agree with the payment method's type or the authority
            // port refuses the preparation; the PayPay method is type `qr`.
            new PaymentTenderPayload($paymentMethodId, TenderKind::Qr, reference: $merchantPaymentId),
            authorizationReference: 'paypay-qr-prepare',
        ));

        // Stamps provider_object_id AND corrects the channel — the attempt
        // skeleton hardcodes `pos`, which would misfile every QR payment.
        $this->persistence->attachCustomerWebPrepareReference(new AttachCustomerWebPrepareReferenceCommand(
            $context,
            $result->attemptId,
            $merchantPaymentId,
        ));

        return [
            'attemptId' => $result->attemptId,
            'merchantPaymentId' => $merchantPaymentId,
        ];
    }

    /**
     * Release an attempt whose QR could not be minted.
     *
     * Mint-time failure only — the raw status says so. For a code that WAS
     * minted and then died at the provider, use `retirePayPayQrAttempt` so the
     * row records PayPay's answer rather than ours.
     */
    public function abandonPayPayQrAttempt(OrderSnapshot $order, string $attemptId, string $merchantPaymentId): void
    {
        $this->finalizePayPayQrAttempt(
            $order,
            $attemptId,
            $merchantPaymentId,
            'abandon',
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Canceled,
                'qr_create_failed',
                new ProviderObjectReference($merchantPaymentId),
            ),
        );
    }

    /**
     * plan-054 — close an attempt whose code will never be paid.
     *
     * Separate from `abandonPayPayQrAttempt` only in what it records as the raw
     * status: that one describes OUR failure to mint, this one carries the
     * answer PayPay gave (`EXPIRED`, `FAILED`, `NOT_FOUND` …). Same terminal
     * state, but an operator reading the row later needs to know whether the
     * code died in our hands or in the customer's.
     *
     * Callers MUST have asked the provider first. Nothing local proves a code
     * went unscanned, and retiring an attempt whose money already moved makes
     * that money unbookable: the webhook path refuses a terminal attempt.
     */
    public function retirePayPayQrAttempt(
        OrderSnapshot $order,
        string $attemptId,
        string $merchantPaymentId,
        string $providerRawStatus,
        int $expectedVersion = 1,
    ): void {
        $this->finalizePayPayQrAttempt(
            $order,
            $attemptId,
            $merchantPaymentId,
            'retire',
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Canceled,
                $providerRawStatus,
                new ProviderObjectReference($merchantPaymentId),
            ),
            $expectedVersion,
        );
    }

    /**
     * plan-054 — record that PayPay confirmed a QR payment.
     *
     * Attempt state ONLY. The ledger row is written by
     * OrderPaymentService::recordPayPayPayment, which owns the money guards,
     * and callers must let it run FIRST: a succeeded attempt is terminal, and
     * every path that books a PayPay payment refuses a terminal attempt. Losing
     * the process between the two therefore has to leave the attempt open (the
     * sweep retries, the ledger call is idempotent) rather than closed with the
     * money unrecorded.
     *
     * @param  int  $amountMinor  What PayPay says it took, never what we asked for.
     */
    public function settlePayPayQrAttempt(
        OrderSnapshot $order,
        string $attemptId,
        string $merchantPaymentId,
        int $amountMinor,
        string $currency,
        int $expectedVersion = 1,
    ): void {
        $this->finalizePayPayQrAttempt(
            $order,
            $attemptId,
            $merchantPaymentId,
            'settle',
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Succeeded,
                'COMPLETED',
                new ProviderObjectReference($merchantPaymentId),
                new Money($amountMinor, strtoupper($currency)),
            ),
            $expectedVersion,
        );
    }

    /**
     * There is no dedicated cancel/settle on the persistence port; finalising
     * with terminal evidence is the established substitute (see
     * finalizeLegacyFail).
     *
     * The provider reference is passed back deliberately on every one of these:
     * it keeps the merchant payment id on the row so a notification for a code
     * we could not delete can still be matched. (`finalizeAttempt` now falls
     * back to the stored value, but being explicit here means this does not
     * depend on that.)
     */
    private function finalizePayPayQrAttempt(
        OrderSnapshot $order,
        string $attemptId,
        string $merchantPaymentId,
        string $reason,
        GatewayPaymentResult $evidence,
        int $expectedVersion = 1,
    ): void {
        $this->payments->finalize(new FinalizePaymentCommand(
            new MutationContext(
                $order->organizationId(),
                self::STRIPE_SYSTEM_ACTOR_ID,
                (string) Str::uuid(),
                'paypay-qr-'.$reason.':'.$merchantPaymentId,
                expectedVersion: max(1, $expectedVersion),
            ),
            $attemptId,
            $evidence,
        ));
    }

    /** Major-unit amount alias for tests and legacy callers. */
    public function prepareStripePaymentAttempt(
        CustomerOrder $order,
        string $paymentIntentId,
        float|int $amount,
        string $currency,
        string $flow = 'full',
    ): ?string {
        $minor = is_int($amount)
            ? $amount
            : $this->toMinorUnits((float) $amount, strtoupper($currency));

        return $this->prepareStripePaymentIntent(
            $order,
            $paymentIntentId,
            $minor,
            strtoupper($currency),
            $flow,
        );
    }

    public function shouldRouteStripeFinalize(PaymentAttempt $attempt): bool
    {
        if (! $this->shouldRouteCustomerWebStripe()) {
            return false;
        }

        $channel = $attempt->channel instanceof PaymentChannelEnum
            ? $attempt->channel
            : PaymentChannelEnum::from((string) $attempt->channel);

        if ($channel !== PaymentChannelEnum::CustomerWeb) {
            return false;
        }

        $state = $attempt->state instanceof PaymentAttemptStateEnum
            ? $attempt->state
            : PaymentAttemptStateEnum::from((string) $attempt->state);

        return ! in_array($state, [
            PaymentAttemptStateEnum::Succeeded,
            PaymentAttemptStateEnum::Failed,
            PaymentAttemptStateEnum::Canceled,
            PaymentAttemptStateEnum::Expired,
        ], true);
    }

    public function finalizeStripePayment(PaymentAttempt $attempt, PaymentIntent $intent): void
    {
        if (! $this->shouldRouteStripeFinalize($attempt)) {
            return;
        }

        $currency = strtoupper((string) ($intent->currency ?: 'JPY'));
        $amountMinor = (int) $intent->amount;

        $this->payments->finalize(new FinalizePaymentCommand(
            $this->stripeMutationContextFromAttempt($attempt),
            (string) $attempt->id,
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Succeeded,
                (string) $intent->status,
                new ProviderObjectReference((string) $intent->id),
                new Money($amountMinor, $currency),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordAutoConfirmTender(
        array $data,
        // #1594 — ID, không phải model. Đo được: method này đọc đúng `$order->id`
        // hai lần (metadata của lệnh, rồi khoá dòng ledger nó vừa ghi) và không
        // gì khác. Nhận model ở đây là giữ một cạnh Payments → Ordering cho một
        // chuỗi.
        string $orderId,
        PaymentMethod $method,
        float $amount,
        float $tipAmount,
        ?array $metadata,
    ): OrderPayment {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $currency = strtoupper((string) ($branch->currency ?? 'JPY'));
        $idempotency = isset($data['idempotency_key']) && is_string($data['idempotency_key'])
            ? $data['idempotency_key']
            : (string) Str::uuid();

        $context = new MutationContext(
            (string) $data['organization_id'],
            (string) $data['received_by_id'],
            (string) Str::uuid(),
            $idempotency,
            expectedVersion: 1,
        );

        $tenderKind = TenderKind::tryFrom((string) $method->type) ?? TenderKind::Other;
        $amountMinor = $this->toMinorUnits($amount, $currency);
        $tipMinor = $this->toMinorUnits($tipAmount, $currency);
        $tenderedMinor = isset($data['tendered_amount'])
            ? $this->toMinorUnits((float) $data['tendered_amount'], $currency)
            : null;

        $this->payments->recordTender(new RecordPaymentTenderCommand(
            $context,
            (string) Str::uuid(),
            $orderId,
            (string) $data['branch_id'],
            $amountMinor,
            $currency,
            new PaymentTenderPayload(
                (string) $method->id,
                $tenderKind,
                tipMinor: $tipMinor,
                tenderedMinor: $tenderedMinor,
                reference: isset($data['reference_no']) && is_string($data['reference_no']) ? $data['reference_no'] : null,
                tillSessionId: isset($data['till_session_id']) ? (string) $data['till_session_id'] : null,
            ),
            authorizationReference: 'legacy-create:'.substr($context->idempotencyKeyHash, 0, 16),
        ));

        $payment = OrderPayment::query()
            ->where('customer_order_id', $orderId)
            ->where('idempotency_key', $idempotency)
            ->first();

        if ($payment === null) {
            throw new InvalidArgumentException('Orchestrator tender did not produce a ledger row.');
        }

        return $this->ledger->updateRow($payment, array_filter([
            'metadata' => $metadata,
            'note' => $data['note'] ?? null,
            'till_session_id' => $data['till_session_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            // #1156 — tender attribution rides the same post-write parity fix
            // as reference_no (PaymentTenderPayload is a canonical mutation
            // payload; extending it would shift idempotency hashes).
            'tender_key' => $data['tender_key'] ?? null,
            // Plan-048 T2.3 — the orchestrator recordTender write does not
            // carry gateway identity (PaymentTenderPayload is a canonical
            // mutation payload; extending it would shift idempotency hashes
            // for offline replays), so parity with the legacy createRow path
            // is restored here, post-write.
            'gateway_option_id' => $data['gateway_option_id'] ?? null,
            'gateway_connection_id' => $data['gateway_connection_id'] ?? null,
            // #2612 — same class of gap as the two lines above, and the one that
            // cost the most: `channel` is the server-owned provenance column, and
            // it was the ONLY field of this class never added here. Auto-confirm
            // methods are exactly the ones a shop's own terminal uses (cash,
            // card_terminal), so the population that reaches Cloud through a
            // device was the population with no provenance — measured on
            // production 2026-08-12 as NULL 128/128, against customer-web 40/40
            // stamped. With the column empty, "which payments did the terminal
            // take?" can only be answered by reading NULL as "workstation", an
            // inference that produced two wrong conclusions on #2410 and blocks
            // the refund guard in #2580 outright.
            'channel' => $this->resolveTransport($data),
        ], static fn ($value): bool => $value !== null));
    }

    public function finalizeLegacyConfirm(OrderPayment $payment): void
    {
        if (! $this->shouldRouteLegacyConfirm($payment)) {
            return;
        }

        $attempt = PaymentAttempt::query()->find($payment->payment_attempt_id);
        if ($attempt === null) {
            return;
        }

        $currency = strtoupper((string) (Branch::query()->find($payment->branch_id)?->currency ?? 'JPY'));
        $amountMinor = $this->toMinorUnits((float) $payment->amount, $currency);

        $this->payments->finalize(new FinalizePaymentCommand(
            $this->mutationContextFromPayment($payment, (int) $attempt->version),
            (string) $attempt->id,
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Succeeded,
                'legacy_confirm',
                new ProviderObjectReference('ledger:'.$payment->id),
                new Money($amountMinor, $currency),
            ),
        ));
    }

    public function finalizeLegacyFail(OrderPayment $payment): void
    {
        if (! $this->shouldRouteLegacyFail($payment)) {
            return;
        }

        $attempt = PaymentAttempt::query()->find($payment->payment_attempt_id);
        if ($attempt === null) {
            return;
        }

        $this->payments->finalize(new FinalizePaymentCommand(
            $this->mutationContextFromPayment($payment, (int) $attempt->version),
            (string) $attempt->id,
            new GatewayPaymentResult(
                PaymentAttemptStateEnum::Failed,
                'legacy_fail',
                new ProviderObjectReference('ledger:'.$payment->id),
            ),
        ));
    }

    private function stripeMutationContextFromAttempt(PaymentAttempt $attempt): MutationContext
    {
        return new MutationContext(
            (string) $attempt->organization_id,
            self::STRIPE_SYSTEM_ACTOR_ID,
            'stripe-finalize:'.$attempt->id,
            'stripe-finalize:'.$attempt->provider_object_id,
            expectedVersion: max(1, (int) $attempt->version),
        );
    }

    private function mutationContextFromPayment(OrderPayment $payment, int $expectedVersion): MutationContext
    {
        $actorId = $payment->received_by_id ?? throw new InvalidArgumentException('Payment actor is required for orchestrator routing.');

        return new MutationContext(
            (string) $payment->organization_id,
            (string) $actorId,
            'legacy-payment:'.$payment->id,
            $payment->idempotency_key ?? 'legacy-payment:'.$payment->id,
            expectedVersion: max(1, $expectedVersion),
        );
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array($currency, ['JPY', 'KRW', 'VND'], true)) {
            return max(0, (int) round($amount));
        }

        return max(0, (int) round($amount * 100));
    }
}
