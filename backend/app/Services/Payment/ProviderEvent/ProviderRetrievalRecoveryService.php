<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentCommand;
use App\Services\Payment\Orchestration\Commands\ReconcilePaymentRefundCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Support\CurrencyMinorUnit;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Retrieves provider truth for timeout/missing-webhook reconciliation cases. */
final class ProviderRetrievalRecoveryService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentMutationFacade $payments,
    ) {}

    public function recoverAttempt(PaymentAttempt $attempt): bool
    {
        if ($attempt->provider_object_id === null) {
            return false;
        }

        $connection = PaymentGatewayConnection::query()->with('provider')->find($attempt->connection_id);
        if ($connection === null) {
            return false;
        }

        // Captured BEFORE the finalize below: finalizeAttempt rewrites
        // provider_object_id from the evidence, so reading it afterwards is
        // reading whatever the provider echoed rather than the merchant payment
        // id this attempt minted. The poll path keys its ledger write on this
        // same value (PayPayPaymentService::syncStatus), and the two only
        // deduplicate against each other if they agree on the id.
        $merchantPaymentId = (string) $attempt->provider_object_id;

        $connectionData = GatewayConnectionDataFactory::fromModel($connection);
        $correlationId = 'recovery:attempt:'.$attempt->id;
        $gateway = $this->gatewayRegistry->forProvider(
            $this->providerCode($connection),
            $correlationId,
        );

        $context = new MutationContext(
            (string) $attempt->organization_id,
            null,
            $correlationId,
            $attempt->id,
            (int) $attempt->version,
        );

        $evidence = $gateway->retrievePayment(new RetrievePaymentCommand(
            $connectionData,
            new GatewayRequestContext(
                $attempt->id,
                'recovery:attempt:'.$attempt->id,
                $correlationId,
            ),
            new ProviderObjectReference((string) $attempt->provider_object_id),
        ));

        if ($evidence->state === PaymentAttemptStateEnum::Succeeded) {
            $this->payments->finalize(new FinalizePaymentCommand($context, (string) $attempt->id, $evidence));

            // plan-054 M4c — the ONLY place a PayPay QR payment reaches the
            // ledger from a webhook. Stripe has a second webhook route that
            // writes order_payments; PayPay has only the inbox, so without this
            // call the attempt flipped to succeeded, the event was marked
            // `orchestrator_paypay_attempt_recovered`, and the order stayed
            // pending with the customer's money recorded nowhere.
            //
            // It must run HERE — after finalize returned, not inside it:
            //   * the attempt lock has already committed, so taking the order
            //     lock next preserves the codebase's order→attempt ordering
            //     (finalizeAttempt holds its attempt lock for its whole body,
            //     so hooking there would invert it against the poll path and
            //     deadlock);
            //   * $evidence is still in scope, and it carries the amount PayPay
            //     actually took. The applicator only gets a bool back, and the
            //     attempt row keeps the amount we ASKED for.
            $this->recordPayPayLedgerEntry($attempt, $merchantPaymentId, $evidence);
        } else {
            $this->payments->reconcile(new ReconcilePaymentCommand($context, (string) $attempt->id, $evidence));
        }

        Log::channel('payment_orchestration')->info('provider_retrieval_recovered_attempt', [
            'attempt_id' => $attempt->id,
            'state' => $evidence->state->value,
            'raw_status' => $evidence->rawStatus,
        ]);

        return true;
    }

    public function recoverRefund(PaymentRefund $refund): bool
    {
        if ($refund->provider_refund_id === null) {
            return false;
        }

        $connection = PaymentGatewayConnection::query()->with('provider')->find($refund->connection_id);
        if ($connection === null) {
            return false;
        }

        $connectionData = GatewayConnectionDataFactory::fromModel($connection);
        $correlationId = 'recovery:refund:'.$refund->id;
        $gateway = $this->gatewayRegistry->forProvider(
            $this->providerCode($connection),
            $correlationId,
        );

        if (! method_exists($gateway, 'retrieveRefund')) {
            throw new RuntimeException('Gateway does not support refund retrieval.');
        }

        $context = new MutationContext(
            (string) $refund->organization_id,
            null,
            $correlationId,
            $refund->id,
            (int) $refund->version,
        );

        $evidence = $gateway->retrieveRefund(new RetrieveRefundCommand(
            $connectionData,
            new GatewayRequestContext(
                $refund->id,
                'recovery:refund:'.$refund->id,
                $correlationId,
            ),
            new ProviderObjectReference((string) $refund->provider_refund_id),
        ));

        $this->payments->reconcileRefund(new ReconcilePaymentRefundCommand($context, (string) $refund->id, $evidence));

        Log::channel('payment_orchestration')->info('provider_retrieval_recovered_refund', [
            'refund_id' => $refund->id,
            'state' => $evidence->state->value,
            'raw_status' => $evidence->rawStatus,
        ]);

        return in_array($evidence->state, [
            PaymentRefundStateEnum::Succeeded,
            PaymentRefundStateEnum::Failed,
            PaymentRefundStateEnum::Canceled,
        ], true);
    }

    /**
     * Book a recovered PayPay payment onto the order's ledger.
     *
     * Gated on the provider so no Stripe/SBPS attempt is double-booked: every
     * other adapter already wrote its order_payments row before it got here.
     *
     * The write itself belongs to OrderPaymentService::recordPayPayPaymentByOrderId,
     * which owns its own transaction and every guard (idempotency, dead order,
     * currency, overpayment). It is resolved lazily rather than injected: the
     * customer service graph is large and pulls the order domain in behind it,
     * and this class is constructed on the webhook worker's hot path.
     */
    private function recordPayPayLedgerEntry(
        PaymentAttempt $attempt,
        string $merchantPaymentId,
        GatewayPaymentResult $evidence,
    ): void {
        if ($this->attemptProviderCode($attempt) !== PaymentGatewayProviderCodeEnum::Paypay) {
            return;
        }

        $money = $evidence->processedMoney;
        $orderId = $attempt->customer_order_id === null ? null : (string) $attempt->customer_order_id;

        // A succeeded GatewayPaymentResult cannot be built without processed
        // money, so a null here means the contract changed under us. Never fall
        // back to $attempt->amount_minor: that is the amount we asked for, and
        // recording it would book a number PayPay never confirmed.
        if ($money === null || $orderId === null || $merchantPaymentId === '') {
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'paypay_ledger_write_impossible', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $orderId,
                'merchant_payment_id' => $merchantPaymentId,
                'has_processed_money' => $money !== null,
            ]);

            return;
        }

        // #1666 (#962) — ảnh chụp qua cổng công bố của Ordering thay vì
        // `CustomerOrder`. CHỈ dùng cho `order_code` trong log bên dưới, và cố ý
        // KHÔNG còn là cổng chặn: cổng lọc thêm `organization_id`, nên nếu dùng
        // nó để quyết có ghi sổ hay không thì một `organization_id` lệch trên
        // attempt sẽ lặng lẽ BỎ QUA một khoản tiền có thật. Việc "đơn còn tồn tại
        // không" do chính hàm ghi sổ trả lời, dưới row lock, qua `order_missing`.
        $order = app(OrderQueryPort::class)->findById(
            (string) $attempt->organization_id,
            $orderId,
        );

        try {
            $outcome = app(OrderPaymentService::class)->recordPayPayPaymentByOrderId(
                $orderId,
                $merchantPaymentId,
                $this->majorUnits($money),
                $money->currency,
                (string) $attempt->id,
            );
        } catch (Throwable $exception) {
            // The order row lock rolled back, so nothing was half-written — but
            // the money IS at PayPay. Name the order before rethrowing: the
            // queue's own failure log knows only the event id.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'paypay_ledger_write_failed', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $orderId,
                'merchant_payment_id' => $merchantPaymentId,
                'amount' => $this->majorUnits($money),
                'currency' => $money->currency,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($outcome['reason'] === 'order_missing') {
            // Cùng sự kiện log như bản trước #1666, chỉ khác chỗ phát hiện: giờ
            // là câu trả lời của hàm ghi sổ dưới row lock, không phải một lần
            // `find()` đoán trước ngoài transaction.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'paypay_ledger_write_order_missing', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $orderId,
                'merchant_payment_id' => $merchantPaymentId,
                'amount' => $this->majorUnits($money),
                'currency' => $money->currency,
            ]);

            return;
        }

        if ($outcome['stranded_amount'] !== null) {
            // Money arrived that the ledger legitimately refuses (order voided,
            // currency mismatch, would overpay). NOT refunded from here: PayPay
            // refunds are deliberately unwired (plan-054 D5), so this log is the
            // hand-off to the operator, who reverses it in the merchant portal.
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_STRANDED, 'paypay_stranded_payment', [
                'attempt_id' => (string) $attempt->id,
                'order_id' => $orderId,
                'order_code' => $order?->orderCode(),
                'merchant_payment_id' => $merchantPaymentId,
                'stranded_amount' => $outcome['stranded_amount'],
                'currency' => $money->currency,
                'reason' => $outcome['reason'],
            ]);

            return;
        }

        Log::channel('payment_orchestration')->info('paypay_recovery_ledger_outcome', [
            'attempt_id' => (string) $attempt->id,
            'order_id' => $orderId,
            'merchant_payment_id' => $merchantPaymentId,
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
        ]);
    }

    /**
     * The ledger stores major units; gateway evidence carries minor ones. PayPay
     * settles in JPY (exponent 0) so this is an identity today, but guessing
     * that from the call site is how a 100× booking error ships.
     */
    private function majorUnits(Money $money): float
    {
        $exponent = CurrencyMinorUnit::exponent($money->currency);

        return $exponent === 0
            ? (float) $money->minorAmount
            : round($money->minorAmount / (10 ** $exponent), $exponent);
    }

    private function attemptProviderCode(PaymentAttempt $attempt): ?PaymentGatewayProviderCodeEnum
    {
        $provider = $attempt->provider;

        return $provider instanceof PaymentGatewayProviderCodeEnum
            ? $provider
            : PaymentGatewayProviderCodeEnum::tryFrom((string) $provider);
    }

    private function providerCode(PaymentGatewayConnection $connection): PaymentGatewayProviderCodeEnum
    {
        $code = $connection->provider->code;

        return $code instanceof PaymentGatewayProviderCodeEnum
            ? $code
            : PaymentGatewayProviderCodeEnum::from((string) $code);
    }
}
