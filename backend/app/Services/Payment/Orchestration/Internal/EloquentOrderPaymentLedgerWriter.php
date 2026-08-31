<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentStatusEnum;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentOrderPaymentLedgerWriter implements OrderPaymentLedgerWriter
{
    public function findByOrderAndIdempotencyKey(string $customerOrderId, string $idempotencyKey): ?OrderPayment
    {
        return OrderPayment::query()
            ->where('customer_order_id', $customerOrderId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByMetadataStripeRefundId(string $stripeRefundId): ?OrderPayment
    {
        return OrderPayment::query()
            ->where('metadata->stripe_refund_id', $stripeRefundId)
            ->first();
    }

    public function findOriginalByReferenceNoForUpdate(string $referenceNo): ?OrderPayment
    {
        return OrderPayment::query()
            ->where('reference_no', $referenceNo)
            ->whereNull('refund_of_id')
            ->lockForUpdate()
            ->first();
    }

    public function sumAbsRefundAmountForOriginal(string $originalPaymentId): float
    {
        return (float) OrderPayment::query()
            ->where('refund_of_id', $originalPaymentId)
            ->sum(DB::raw('ABS(amount)'));
    }

    public function lockById(string $paymentId): OrderPayment
    {
        $payment = OrderPayment::query()->lockForUpdate()->find($paymentId);

        if ($payment === null) {
            throw (new ModelNotFoundException)->setModel(OrderPayment::class, [$paymentId]);
        }

        return $payment;
    }

    public function createRow(array $paymentData): OrderPayment
    {
        return $this->createWithUniqueCode($paymentData);
    }

    public function updateRow(OrderPayment $payment, array $attributes): OrderPayment
    {
        $payment->update($attributes);

        return $payment->fresh() ?? $payment;
    }

    public function expireIfStalePending(string $paymentId): ?OrderPayment
    {
        return DB::transaction(function () use ($paymentId): ?OrderPayment {
            $payment = OrderPayment::query()->lockForUpdate()->find($paymentId);
            if ($payment === null) {
                return null;
            }

            $status = $payment->status instanceof PaymentStatusEnum
                ? $payment->status
                : PaymentStatusEnum::from($payment->status);

            if ($status !== PaymentStatusEnum::Pending) {
                return null;
            }
            if ($payment->expires_at === null || $payment->expires_at->isFuture()) {
                return null;
            }

            return $this->updateRow($payment, ['status' => PaymentStatusEnum::Failed->value]);
        });
    }

    public function stampTillSessionIds(array $paymentIds, string $tillSessionId): int
    {
        if ($paymentIds === []) {
            return 0;
        }

        return OrderPayment::query()
            ->whereIn('id', $paymentIds)
            ->update(['till_session_id' => $tillSessionId]);
    }

    /**
     * plan-054 T4.3 — `order_payments` carries TWO unique constraints, and only
     * one of them is retryable:
     *
     *   - `payment_code` — a sequence this class generates. A clash means two
     *     writers picked the same number; bumping the offset and re-inserting is
     *     the correct answer.
     *   - `(customer_order_id, idempotency_key)` — the DB backstop against
     *     booking the same money twice. plan-054 T4.2 stamps the PayPay
     *     merchantPaymentId there, so this firing means "this payment is already
     *     in the ledger". Retrying it is not just useless, it is four more
     *     inserts of a duplicate payment against a live money table, and the
     *     caller is then told the failure was about payment codes — which sends
     *     whoever reads the log looking in exactly the wrong place.
     *
     * So: retry only the code clash, rethrow everything else untouched and let
     * the caller see the real constraint.
     *
     * @param  array<string, mixed>  $paymentData
     */
    private function createWithUniqueCode(array $paymentData): OrderPayment
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $paymentData['payment_code'] = $this->generateCode($attempt);
            try {
                return OrderPayment::create($paymentData);
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->violationIsOnPaymentCode($exception)) {
                    throw $exception;
                }

                if ($attempt === 4) {
                    throw new \RuntimeException(
                        'Could not generate a unique payment code after several attempts.',
                        previous: $exception
                    );
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique payment code.');
    }

    /**
     * Which constraint did the driver complain about?
     *
     * Read ONLY the driver's own message (`getPrevious()`, the PDOException).
     * `QueryException::getMessage()` prepends the failing SQL, and that SQL is
     * an INSERT listing every column — including `payment_code`. Matching
     * against it says "payment code" for literally every unique violation on
     * this table, which is the bug this method exists to fix. (Measured the hard
     * way: the first cut of this check read both strings and the idempotency
     * test still went down the retry path.)
     *
     * Both engines name the offending constraint in the driver message — MySQL
     * as the index (`… for key 'order_payments.order_payments_payment_code_unique'`),
     * SQLite as the column list (`UNIQUE constraint failed: order_payments.payment_code`).
     * Neither spelling of `(customer_order_id, idempotency_key)` contains
     * `payment_code`.
     *
     * No driver message, or one we cannot read, counts as NOT the code: an
     * unreadable error must surface itself rather than be silently retried five
     * times against a money table.
     */
    private function violationIsOnPaymentCode(UniqueConstraintViolationException $exception): bool
    {
        $driverMessage = $exception->getPrevious()?->getMessage();

        if ($driverMessage === null) {
            return false;
        }

        return str_contains($driverMessage, 'payment_code');
    }

    private function generateCode(int $extraOffset = 0): string
    {
        $year = now()->year;
        $prefix = "PAY-{$year}-";
        $lastNumber = OrderPayment::withTrashed()
            ->where('payment_code', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(payment_code, ?) AS UNSIGNED)) as last_num', [strlen($prefix) + 1])
            ->value('last_num') ?? 0;

        return $prefix.sprintf('%04d', (int) $lastNumber + 1 + $extraOffset);
    }
}
