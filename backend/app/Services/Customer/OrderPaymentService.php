<?php

namespace App\Services\Customer;

use App\Events\OrderPaymentRecorded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Models\TillSession;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\BeginOrderPaymentCommand;
use App\Services\Order\Commands\RefreshOrderPaymentCacheCommand;
use App\Services\Order\Commands\SettleOrderIfPaidCommand;
use App\Services\Order\Commands\StampOrderStripeIntentCommand;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Order\Contracts\BranchSplitBillPolicy;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSplitBillTotals;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\PayPay\PayPayQrSplitIntent;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use App\Services\Payment\Orchestration\Internal\PayPayCanonicalPaymentMethodProvisioner;
use App\Services\Payment\Orchestration\Internal\StripeCanonicalPaymentMethodProvisioner;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Policy\Admin\PosEffectivePaymentOptionEnricher;
use App\Services\Payment\Policy\PaymentPolicySubmissionValidator;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySubmission;
use App\Services\Pos\TillSessionService;
use App\Support\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrderPaymentService
{
    public function __construct(
        private readonly OrderPaymentLedgerWriter $ledgerWriter,
        private readonly OrderMutationFacade $orderService,
        private readonly OrderPaymentOrchestrationCompat $orchestrationCompat,
        // #1594 — cổng của Ordering thay cho chính bộ tính. Trước đây Payments
        // cầm `SplitByItemsCalculator`, một class của Ordering nhận
        // `CustomerOrder` — tức phải phụ thuộc cả aggregate đơn hàng để so MỘT
        // con số. Phép tính không đổi: cổng gọi đúng bộ tính đó.
        private readonly OrderSplitBillTotals $splitBillTotals,
        private readonly TillSessionService $tillSessions,
        private readonly PaymentPolicySubmissionValidator $policySubmissionValidator,
        // #962 — hai cổng Ordering công bố, thay cho việc Payments đọc thẳng
        // `ShopOrderSetting` và gọi `OrderClosingService::isPaidEnough` dạng static.
        private readonly BranchSplitBillPolicy $splitBillPolicy,
        private readonly OrderQueryPort $orders,
        // #1856 — injected, not resolved through `app()` inside the method.
        //
        // `internalTenderMethodIds()` memoises per shop on the INSTANCE, and a
        // fresh `app()` resolve per payment threw that away: measured at 4
        // queries on every payment that reaches the internal-tender check.
        // Injecting also matches how every other collaborator above arrives.
        private readonly PosEffectivePaymentOptionEnricher $internalTenderCatalog,
    ) {}

    /**
     * Đơn đã trả đủ chưa — hỏi ORDERING, không tự trừ.
     *
     * `OrderQueryPort::isPaidInFull` CHUYỂN TIẾP sang đúng
     * `OrderClosingService::isPaidEnough` cũ (dung sai làm tròn lấy từ
     * `shop_order_settings.currency_code` — #821 E3), nên đây là đổi ĐƯỜNG ĐI,
     * không đổi phép tính. Mọi call site đều gọi ngay sau `$order->refresh()`,
     * nên hàng cổng đọc lại là hàng người gọi vừa đọc.
     */
    private function isOrderPaidInFull(CustomerOrder $order): bool
    {
        return $this->orders->isPaidInFull(
            (string) $order->organization_id,
            (string) $order->id,
        );
    }

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * List payments for an order (unpaginated — typically < 10 per order).
     *
     * @param  array{customer_order_id: string}  $filters
     * @return Collection<int, OrderPayment>
     */
    public function list(array $filters): Collection
    {
        return OrderPayment::query()
            ->where('customer_order_id', $filters['customer_order_id'])
            ->with('paymentMethod')
            ->orderBy('created_at')
            ->get();
    }

    // =========================================================================
    //  Create
    // =========================================================================

    /**
     * @param  array{customer_order_id: string, payment_method_id: string, amount: float, received_by_id: string, organization_id: string, branch_id: string, brand_id: string, tip_amount?: float, tendered_amount?: float, reference_no?: string, note?: string, metadata?: array<string, mixed>}  $data
     */
    public function create(array $data): OrderPayment
    {
        // #2860 — chuẩn hoá từ vựng chia bill NGAY tại biên vào, trước mọi phép
        // so sánh bên dưới. Fleet là hai máy Windows không tự cập nhật và kiosk
        // là app native trên tablet, nên tên cũ (`equal`, `custom`, …) còn tới
        // đây một thời gian; nhưng từ dòng này trở xuống — và trong cột
        // `order_payments.metadata` — chỉ tồn tại từ vựng canonical.
        if (isset($data['metadata']) && is_array($data['metadata'])
            && ($data['metadata']['split_mode'] ?? null) !== null) {
            $data['metadata']['split_mode'] = OrderSplitMode::canonicalWire(
                (string) $data['metadata']['split_mode']
            );
        }

        return DB::transaction(function () use ($data) {
            /** @var CustomerOrder $order */
            $order = CustomerOrder::lockForUpdate()->findOrFail($data['customer_order_id']);

            // Idempotency: now that we hold the per-order lock, any prior
            // request with the same key has already committed (READ COMMITTED
            // — its row is visible). Returning the existing payment is the
            // correct answer for retries after a network glitch where the
            // server got the request but the client never saw the response.
            // Scoped to (order_id, key) so a hypothetical cross-order UUID
            // collision can't return a payment from a different order.
            if ($key = $data['idempotency_key'] ?? null) {
                $existing = $this->ledgerWriter->findByOrderAndIdempotencyKey($order->id, $key);
                if ($existing) {
                    return $existing->load('paymentMethod');
                }
            }

            $orderStatus = $order->status instanceof CustomerOrderStatusEnum
                ? $order->status
                : CustomerOrderStatusEnum::from($order->status);

            // plan-031 — block payment after the takeaway payment window elapses.
            //
            // The status guard below only rejects an order the sweep job
            // (CancelOverdueTakeawayOrders, every 60s) has ALREADY moved to
            // `expired`. Between the deadline passing and the next sweep tick a
            // takeaway order can still sit in `checkout`/`paying` (the cashier
            // pulled it into checkout just before the deadline), so a raw status
            // check leaves a race window in which an overdue order is still
            // payable. This runs under the same per-order `lockForUpdate` the
            // create() path already holds, so it is race-safe against a
            // concurrent expire(): whichever transaction commits first, a
            // payment on an order whose `payment_due_at` has passed is rejected
            // deterministically. `payment_due_at` is only ever stamped on
            // takeaway counter-pay orders, but we gate on order_type too so the
            // intent is explicit and future non-takeaway uses of the column stay
            // unaffected.
            $orderType = $order->order_type instanceof CustomerOrderTypeEnum
                ? $order->order_type
                : ($order->order_type !== null ? CustomerOrderTypeEnum::tryFrom($order->order_type) : null);

            if ($orderType === CustomerOrderTypeEnum::Takeaway
                && $order->payment_due_at !== null
                && $order->payment_due_at->isPast()) {
                abort(response()->json([
                    'message' => 'The takeaway payment window has elapsed. This order can no longer be paid and will be auto-cancelled.',
                    'code' => 'takeaway_payment_window_elapsed',
                    'payment_due_at' => $order->payment_due_at->toISOString(),
                ], 422));
            }

            if (! in_array($orderStatus, [CustomerOrderStatusEnum::Checkout, CustomerOrderStatusEnum::Paying], true)) {
                abort(409, "Order must be in 'checkout' or 'paying' status to accept a payment. Current: {$orderStatus->value}");
            }

            // M3 (#555) — close()↔payment race. The ResolveOpenTillSession
            // middleware resolved this till_session_id BEFORE we acquired the
            // per-order lock. A concurrent close()/abandon()/expire could have
            // settled the shift in between; stamping this payment onto a now-
            // settled session orphans the money against an already-reconciled
            // Z-report (its cash never lands in any open drawer's expected_cash).
            // Re-lock the session row and re-assert it still owns the till before
            // stamping. lockForUpdate serialises against close()'s own session
            // lock: whichever tx commits first wins, and the loser sees the
            // authoritative status. Only the pos path carries a session id — the
            // customer / kiosk / refund paths pass null and skip this guard.
            if (! empty($data['till_session_id'])) {
                /** @var TillSession|null $session */
                $session = TillSession::query()
                    ->lockForUpdate()
                    ->find($data['till_session_id']);

                $sessionStatus = $session === null
                    ? null
                    : ($session->status instanceof TillSessionStatusEnum
                        ? $session->status
                        : TillSessionStatusEnum::from($session->status));

                // Accept the same in-progress set the middleware accepted
                // (open OR closing — both still own the till lock). Anything
                // terminal (settled / abandoned / expired) or a vanished row
                // means the shift closed under us: fail loudly so the client
                // re-resolves an open shift instead of silently orphaning cash.
                if (! in_array($sessionStatus, [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing], true)) {
                    abort(response()->json([
                        'message' => 'The cashier shift closed before this payment was recorded. Re-open a shift and retry.',
                        'code' => 'NO_OPEN_SHIFT',
                    ], 409));
                }
            }

            // Drift guard for split-bill: client snapshot the per-person amounts
            // against `expected_total_amount`. If staff voided an item or applied
            // a discount in the meantime, the order total moved and the snapshot
            // is stale — fail loudly with a structured code so the dialog can
            // surface a "tính lại" prompt instead of the generic overpayment
            // error that confused staff before.
            if (array_key_exists('expected_total_amount', $data) && $data['expected_total_amount'] !== null) {
                $expected = (float) $data['expected_total_amount'];
                $actual = (float) $order->total_amount;
                if (abs($expected - $actual) > 0.001) {
                    abort(response()->json([
                        'message' => 'Order total has changed since the split-bill snapshot was taken. Recalculate the split.',
                        'code' => 'split_bill_total_drift',
                        'expected_total_amount' => number_format($expected, 2, '.', ''),
                        'actual_total_amount' => number_format($actual, 2, '.', ''),
                    ], 422));
                }
            }

            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = PaymentMethod::findOrFail($data['payment_method_id']);

            $policySubmission = PaymentPolicySubmission::fromPaymentData($data, (string) $order->branch_id);
            if ($policySubmission !== null) {
                $this->assertPolicyAllowedOrObserve($policySubmission, $data, $order, $paymentMethod);
            } else {
                $this->handleMissingPolicyOption($data, $order, $paymentMethod);
            }

            if (! $paymentMethod->is_active) {
                abort(response()->json([
                    'message' => 'Payment method is inactive.',
                    'code' => 'payment_method_inactive',
                ], 422));
            }

            $tipAmount = (float) ($data['tip_amount'] ?? 0);
            $amount = (float) $data['amount'];

            // Plan 033 — by-items split validation (4 structured 422 codes).
            // Fires only when metadata.split_mode === 'by_items' AND
            // metadata.item_allocations is a non-empty array AND this is not
            // a refund row. Bypassed for the customer-web Stripe path which
            // never carries item_allocations (Q3 + Q4 in DESIGN.md).
            $this->validateByItemsAllocations($order, $data, $amount);

            // Overpayment guard — sum of currently-claiming + succeeded
            // + this new amount. \"Currently-claiming\" = pending payments
            // whose 15-minute hold window has NOT lapsed (the row was
            // created with `expires_at = created_at + 15min` when
            // `is_auto_confirm = false` — see paymentData below).
            //
            // Why exclude expired pending: a stuck pending row from a
            // crashed/abandoned cash session that nobody confirmed-or-
            // failed used to block ALL future payments on the same
            // order — cashier sees \"Payment amount exceeds the
            // outstanding order balance\" with no obvious cause and
            // can't recover without DBA help.
            //
            // The 15-minute hold is the contract the kiosk/customer flow
            // commits to: any pending payment older than that should be
            // considered abandoned. NULL expires_at means \"no hold\"
            // (auto-confirm methods like cash transition straight to
            // succeeded so they shouldn't have pending rows; defensive
            // coalesce just in case).
            // #821 A4 — a settlement is NOT a payment for this order. It is cash
            // collected against an OLD on_account debt, riding on whatever order
            // happens to be open so it lands in the till session. Counting it
            // against this order's balance made the feature incoherent in both
            // directions: paying the debt in full was rejected as an overpayment
            // (the debt almost never equals the new order's total), while paying
            // a token amount sailed through and silently wiped the whole debt.
            //
            // Excluded here AND from updateOrderPaymentCache(), so it neither
            // blocks nor inflates the order it rides on. The amount is instead
            // pinned to the debt itself in OrderPaymentStoreRequest
            // (settles_amount_mismatch).
            $isSettlement = ! empty($data['metadata']['settles_payment_id']);

            // #816 — money that LANDED is `succeeded` + `refunded`, never
            // `succeeded` alone. A refund keeps the original's +X and flips it
            // to `refunded`, then adds a -X `succeeded` row; counting only
            // `succeeded` dropped the +X and kept the -X, so each refund
            // subtracted its amount TWICE. On an 800 order with a fully
            // refunded 300 payment that yielded existingPaidTotal = -300 and
            // outstanding = 1100 — the guard would happily accept 1100 on an
            // 800 order. Same defect as the void guard; same fix as
            // OrderPayment::netCollectedForOrder(), which this cannot reuse
            // verbatim because it deliberately also counts in-flight `pending`.
            $existingPaidTotal = (float) OrderPayment::query()
                ->where('customer_order_id', $order->id)
                ->whereNull('metadata->settles_payment_id')
                ->where(function ($q) {
                    $q->whereIn('status', [
                        PaymentStatusEnum::Succeeded->value,
                        PaymentStatusEnum::Refunded->value,
                    ])
                        ->orWhere(function ($pending) {
                            $pending->where('status', PaymentStatusEnum::Pending->value)
                                ->where(function ($notExpired) {
                                    $notExpired->whereNull('expires_at')
                                        ->orWhere('expires_at', '>=', now());
                                });
                        });
                })
                ->sum('amount');

            $outstanding = (float) $order->total_amount - $existingPaidTotal;
            $overpay = $isSettlement ? 0.0 : $amount - $outstanding;
            if ($overpay > 0.0) {
                // Rounding tolerance for integer-currency workstations. The
                // workstation stores money as whole units (a JPY/VND
                // assumption); on a fractional-currency order (USD/EUR) the
                // final counter-pay installment can exceed the cloud outstanding
                // by a sub-unit rounding remainder — e.g. a 1782.50 total leaves
                // 511.50 outstanding but the workstation sends 512. Clamp the
                // recorded amount to the exact outstanding so the order settles
                // instead of getting stuck "paying" forever (customer-web then
                // never receives OrderPaid and the QR screen never flips to
                // success).
                //
                // For by_items splits, each sub-check independently rounds
                // tax + service up (`SplitByItemsCalculator::compute` uses
                // `computeBillTotal(reconcile: false)`), so Σ per-bill totals
                // can drift from `order.total_amount` by up to
                // `bills_count × 2` units (tax + service rounding per bill).
                // A rigid 1-unit tolerance blocks the last cashier from
                // settling the order — widen to the maximum theoretical drift
                // for by_items so the closing payment lands.
                $tolerance = 1.0;
                if (($data['metadata']['split_mode'] ?? null) === OrderSplitMode::ByItems->value) {
                    $totalBills = (int) ($data['metadata']['total_bills'] ?? 2);
                    $tolerance = max(1.0, (float) $totalBills * 2.0);
                }
                if ($overpay <= $tolerance) {
                    $amount = $outstanding;
                } else {
                    // #417 Tầng 3 — structured error so the FE can render an
                    // actionable message instead of the opaque generic 422 that
                    // confused staff. When a not-yet-expired pending payment is
                    // reserving part of the balance, surface that amount so the
                    // dialog can say "¥X is held by an in-progress payment — wait
                    // or ask the cashier" rather than "amount exceeds balance".
                    // Stuck (expired) pendings are already excluded from the sum
                    // above and auto-failed by `payments:expire-stale`, so the
                    // remaining pending here is a live one.
                    $pendingHold = (float) OrderPayment::query()
                        ->where('customer_order_id', $order->id)
                        ->where('status', PaymentStatusEnum::Pending->value)
                        ->where(function ($notExpired) {
                            $notExpired->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', now());
                        })
                        ->sum('amount');

                    abort(response()->json([
                        'message' => 'Payment amount exceeds the outstanding order balance.',
                        'code' => 'overpayment_blocked',
                        'outstanding_amount' => number_format(max($outstanding, 0.0), 2, '.', ''),
                        'given_amount' => number_format($amount, 2, '.', ''),
                        'pending_hold_amount' => number_format($pendingHold, 2, '.', ''),
                    ], 422));
                }
            }

            // Plan-007 (Decision 12 / NOTES 2026-04-20) — walk-in partial-payment
            // black hole. A shortfall payment on an order with no customer_id has
            // no recovery key: GET /customers/{id}/outstanding is the only
            // mechanism that resurfaces the debt, so a walk-in partial would
            // strand the balance forever and corrupt shift reconciliation. Enforced
            // only on the POS/shop namespace (enforce_walkin_full_payment) and
            // never for split-bill installments, which are partial by design and
            // tracked via their own split plan. Runs after the overpay clamp so a
            // full payment nudged down to $outstanding is not mistaken for a
            // shortfall.
            // #2856 — tín hiệu "khoản này là một phần của chia bill" phải đến
            // từ CHÍNH khoản thanh toán, không từ một cột trên đơn.
            //
            // Vế `$order->split_mode === null` từng đứng ở đây và nó là một
            // lỗ tiền: `POST /customer/orders/{id}/split-mode` CÔNG KHAI có
            // chủ đích (chú thích tại route: `auth:customer` sẽ làm hỏng
            // luồng guest counter-pay), nên khách cầm mã đơn gọi một lần là
            // đơn thoát khỏi luật "walk-in phải trả đủ" — thứ tồn tại vì một
            // khoản thiếu trên đơn KHÔNG có `customer_id` không có khoá nào
            // để tra lại, và nó làm hỏng đối soát ca.
            //
            // Vế payment-level dưới đây phủ đúng ca hợp lệ: POS gửi nó theo
            // TỪNG giao dịch, khách không đặt được. Ruling chủ dự án
            // 2026-08-15: chia bill chỉ là chia HÌNH THỨC THANH TOÁN, không
            // được đổi order — nên trạng thái trình bày của thanh toán cũng
            // không được gác một luật tiền.
            if (($data['enforce_walkin_full_payment'] ?? false)
                && $order->customer_id === null
                && empty($data['metadata']['split_mode'] ?? null)
                && ($outstanding - $amount) > 0.001) {
                abort(response()->json([
                    'message' => 'A walk-in order must be paid in full. Attach a customer to record a partial balance.',
                    'code' => 'walkin_partial_payment_blocked',
                    'outstanding_amount' => number_format(max($outstanding, 0.0), 2, '.', ''),
                    'given_amount' => number_format($amount, 2, '.', ''),
                ], 422));
            }

            $changeAmount = null;

            if ($paymentMethod->requires_tendered) {
                $tenderedAmount = $data['tendered_amount'] ?? null;

                if ($tenderedAmount === null || (float) $tenderedAmount < ($amount + $tipAmount)) {
                    throw new InvalidArgumentException(
                        'Tendered amount must be provided and must be >= payment amount + tip amount.'
                    );
                }

                $changeAmount = (float) $tenderedAmount - $amount - $tipAmount;
                $data['tendered_amount'] = (float) $tenderedAmount;
                $data['change_amount'] = $changeAmount;
            }

            $isAutoConfirm = $paymentMethod->is_auto_confirm;

            $metadata = $this->backfillByPeopleSplitMetadata($order, $data['metadata'] ?? null, $amount);

            // Resolve the originating app server-side. Stamped onto the dedicated
            // `channel` column below — NEVER into the caller-owned `metadata` blob.
            // Writing it into metadata (a) broke the "no split → metadata null"
            // contract (#1058) and (b) let a client override the value that steers
            // confirm/fail routing, since resolveTransport honours a request-supplied
            // metadata.channel (#1059). The column is server-only.
            $channel = $this->orchestrationCompat->resolveTransport($data);

            if (array_key_exists('policy_revision', $data) && $data['policy_revision'] !== null) {
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['payment_policy_revision'] = (int) $data['policy_revision'];
            }

            // #1111 — a client-supplied gateway identity must belong to this
            // payment's organization: request validation only checks row
            // existence, so a paired device could otherwise stamp another
            // org's connection uuid onto its ledger rows.
            if (! empty($data['gateway_connection_id'])) {
                $connectionInOrg = PaymentGatewayConnection::query()
                    ->whereKey($data['gateway_connection_id'])
                    ->where('organization_id', $data['organization_id'])
                    ->exists();

                if (! $connectionInOrg) {
                    throw new InvalidArgumentException(
                        'Gateway connection does not belong to this organization.'
                    );
                }
            }

            $paymentData = [
                'customer_order_id' => $order->id,
                'payment_method_id' => $data['payment_method_id'],
                'amount' => $amount,
                'tip_amount' => $tipAmount,
                'status' => $isAutoConfirm
                    ? PaymentStatusEnum::Succeeded->value
                    : PaymentStatusEnum::Pending->value,
                'paid_at' => $isAutoConfirm ? now() : null,
                'expires_at' => $isAutoConfirm ? null : now()->addMinutes(15),
                'tendered_amount' => $data['tendered_amount'] ?? null,
                'change_amount' => $changeAmount,
                'reference_no' => $data['reference_no'] ?? null,
                // #1156 — brand-level tender attribution (validated in
                // OrderPaymentStoreRequest; optional on every path).
                'tender_key' => $data['tender_key'] ?? null,
                'note' => $data['note'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'metadata' => $metadata,
                'channel' => $channel,
                'gateway_option_id' => $data['gateway_option_id'] ?? null,
                'gateway_connection_id' => $data['gateway_connection_id'] ?? null,
                'received_by_id' => $data['received_by_id'],
                // Plan 030 — Cashier Shift: stamp the open TillSession id
                // (set by ResolveOpenTillSession middleware on pos endpoints;
                // null on customer / kiosk / refund paths that bypass the guard).
                'till_session_id' => $data['till_session_id'] ?? null,
                'organization_id' => $data['organization_id'],
                'branch_id' => $data['branch_id'],
                // #1800 — the ORDER owns the brand, not the request context.
                // Callers derive brand_id from wherever they happen to stand:
                // POS from `$request->attributes`, kiosk/workstation from
                // `$branch->brand->id`. None of them consult the order, so a
                // branch whose brand differs from the order's silently wrote a
                // payment into the wrong brand — invisible to money totals, but
                // it corrupts brand-scoped reporting, and nothing downstream
                // re-derives it. The mismatch is corrected below and logged as
                // `payment_brand_corrected_from_order` — that log line is the
                // only signal, so treat it as one.
                // Both columns are NOT NULL, so the locked order is always a
                // complete answer and no fallback is needed.
                'brand_id' => $order->brand_id,
            ];

            if ((string) ($data['brand_id'] ?? '') !== (string) $order->brand_id) {
                // Corrected rather than rejected: throwing here would turn a
                // reporting-attribution defect into a refused payment at the
                // counter. Logged so the offending transport is findable
                // instead of silently patched forever.
                Log::channel('payment_orchestration')->warning('payment_brand_corrected_from_order', [
                    'order_id' => (string) $order->id,
                    'order_brand_id' => (string) $order->brand_id,
                    'caller_brand_id' => $data['brand_id'] ?? null,
                    'transport' => $channel,
                ]);
            }

            // If this is the first payment and order is still at checkout → move to paying
            if ($orderStatus === CustomerOrderStatusEnum::Checkout) {
                $this->orderService->beginPaying(new BeginOrderPaymentCommand(
                    $this->mutationContextFromPaymentData($data, $order),
                    (string) $order->id,
                ));
                $order->refresh();
            }

            /** @var OrderPayment $payment */
            if ($isAutoConfirm && $this->orchestrationCompat->shouldRouteAutoConfirmCreate($data, $paymentMethod)) {
                $payment = $this->orchestrationCompat->recordAutoConfirmTender(
                    $data,
                    (string) $order->id,
                    $paymentMethod,
                    $amount,
                    $tipAmount,
                    $metadata,
                );
            } else {
                $payment = $this->ledgerWriter->createRow($paymentData);
            }

            if ($isAutoConfirm) {
                $this->updateOrderPaymentCache($order->fresh());

                $order->refresh();

                if ($this->isOrderPaidInFull($order)) {
                    $this->settleIfPaid(
                        $order,
                        $data['received_by_id'],
                        isset($data['idempotency_key']) ? 'settle-'.$data['idempotency_key'] : null,
                    );
                } else {
                    // Partial payment — customer-web's dine-in payment view
                    // subscribes to this so a guest watching the QR sees the
                    // by-items panel auto-refresh when a sibling pays at the
                    // kiosk. The full-close path already broadcasts the
                    // separate `order.paid` event from OrderClosingService.
                    OrderPaymentRecorded::dispatch($order, $payment);
                }
            }

            $payment->logAudit('payment_created', ['amount' => $payment->amount]);

            return $payment->load('paymentMethod');
        });
    }

    /**
     * Plan-033 by-items validation (plan đã xoá #2188 — git history).
     *
     * Runs under the per-order lock already acquired by create(). All abort()
     * calls produce a 422 with a structured `code` so the FE can render an
     * actionable toast and refetch the preview endpoint.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateByItemsAllocations(CustomerOrder $order, array $data, float $amount): void
    {
        $metadata = $data['metadata'] ?? null;

        // Skip when not a by-items split.
        if (! is_array($metadata) || ($metadata['split_mode'] ?? null) !== OrderSplitMode::ByItems->value) {
            return;
        }

        // Skip when this is a refund row (refunds re-use the payment shape but
        // bypass split semantics — Q4).
        if (! empty($data['refund_of_id'])) {
            return;
        }

        $allocations = $metadata['item_allocations'] ?? null;

        // Defensive bypass: empty array or missing field is treated as "not a
        // by-items payment" so customer-web payloads carrying the field for
        // forward-compat don't trip the validator (Q3).
        if (! is_array($allocations) || count($allocations) === 0) {
            return;
        }

        // Eager-load items if not already loaded by the drift-guard branch.
        if (! $order->relationLoaded('items')) {
            $order->load('items');
        }

        $itemsById = [];
        foreach ($order->items as $item) {
            $itemsById[(string) $item->id] = $item;
        }

        // (1) unknown_item / voided_item per allocation.
        foreach ($allocations as $alloc) {
            $itemId = (string) ($alloc['item_id'] ?? '');
            if ($itemId === '' || ! isset($itemsById[$itemId])) {
                abort(response()->json([
                    'message' => 'Allocation references an item that does not belong to this order.',
                    'code' => 'split_by_items_unknown_item',
                    'item_id' => $itemId,
                ], 422));
            }
            $statusRaw = $itemsById[$itemId]->status;
            $statusValue = is_object($statusRaw) && property_exists($statusRaw, 'value') ? $statusRaw->value : $statusRaw;
            if ($statusValue === 'voided') {
                abort(response()->json([
                    'message' => 'Allocation references a voided item.',
                    'code' => 'split_by_items_voided_item',
                    'item_id' => $itemId,
                ], 422));
            }
        }

        // (2) double-claim — cumulative units across non-failed payments must
        // not exceed item.quantity. Aggregate prior claims from existing
        // payments' metadata first, then compare against item.quantity.
        $existingClaims = OrderPayment::query()
            ->where('customer_order_id', $order->id)
            ->whereIn('status', [
                PaymentStatusEnum::Pending->value,
                PaymentStatusEnum::Succeeded->value,
            ])
            ->get(['metadata', 'amount', 'refund_of_id']);

        // Mutual exclusivity — once the order has committed to another split
        // strategy (pay-in-full, equal split, or custom-amount split — none of
        // which carry split_mode === 'by_items'), the by-items mode is locked.
        // Mixing strategies double-counts the same items against the order
        // total, so we reject early with a structured code letting the FE
        // disable the by-items option instead of starting an unwinnable flow.
        // Refund rows (negative amount / refund_of_id set) re-use the payment
        // shape but never carry split metadata — they must not trip the lock.
        foreach ($existingClaims as $row) {
            if ($row->refund_of_id !== null || (float) $row->amount < 0) {
                continue;
            }
            $rowMeta = $row->metadata;
            $rowMode = is_array($rowMeta) ? ($rowMeta['split_mode'] ?? null) : null;
            if ($rowMode !== 'by_items') {
                abort(response()->json([
                    'message' => 'This order already has a payment using another split mode. By-items split is locked.',
                    'code' => 'split_by_items_mode_locked',
                ], 422));
            }
        }

        $claimedByItem = [];
        foreach ($existingClaims as $row) {
            $rowMeta = $row->metadata;
            if (! is_array($rowMeta) || ($rowMeta['split_mode'] ?? null) !== 'by_items') {
                continue;
            }
            $rowAllocs = $rowMeta['item_allocations'] ?? [];
            if (! is_array($rowAllocs)) {
                continue;
            }
            foreach ($rowAllocs as $rowAlloc) {
                $rowItemId = (string) ($rowAlloc['item_id'] ?? '');
                $rowUnits = (int) ($rowAlloc['units'] ?? 0);
                if ($rowItemId !== '' && $rowUnits > 0) {
                    $claimedByItem[$rowItemId] = ($claimedByItem[$rowItemId] ?? 0) + $rowUnits;
                }
            }
        }

        // #2180 — "một suất" phải cùng nghĩa với SplitByItemsCalculator: đã trừ
        // refunded_quantity (#2159). Bản cũ dùng quantity thô nên client chưa
        // cập nhật (tab cũ / gọi thẳng API) gửi đủ số suất gốc vẫn lọt qua tầng
        // này và chỉ bị chặn nhờ cổng overpayment. Hỏi qua cổng
        // `OrderSplitBillTotals` chứ không gọi calculator — deptrac chặn
        // Payments → Ordering, cùng ranh giới mà `billTotalFor` (#1594) đã giữ.
        $splittableByItem = $this->splitBillTotals->splittableUnitsByItem((string) $order->id);

        // Layer this allocation on top.
        foreach ($allocations as $alloc) {
            $itemId = (string) $alloc['item_id'];
            $units = (int) $alloc['units'];
            $cumulative = ($claimedByItem[$itemId] ?? 0) + $units;
            $itemQty = $splittableByItem[$itemId] ?? 0;

            if ($cumulative > $itemQty) {
                abort(response()->json([
                    'message' => 'Item is already fully allocated to other sub-checks.',
                    'code' => 'split_by_items_double_claim',
                    'item_id' => $itemId,
                    'item_quantity' => $itemQty,
                    'claimed_units' => $cumulative,
                ], 422));
            }
        }

        // (3) total mismatch — recompute the expected sub-check total via the
        // shared calculator and compare against the request `amount` within a
        // 1-minor-unit tolerance.
        // #962 — `shop_order_settings` là bảng của Ordering. Ba cột này (chế độ
        // làm tròn, tiền tệ, phí phục vụ) giờ đọc qua cổng `BranchSplitBillPolicy`
        // cùng đúng bộ mặc định cũ; phép tính vẫn ở `SplitByItemsCalculator`.
        $setting = $this->splitBillPolicy->forBranch(
            $order->branch_id === null ? null : (string) $order->branch_id,
        );
        $roundingMode = $setting->roundingMode;
        $currencyCode = $setting->currencyCode;
        $taxRate = 0.0 /* plan-043 T6.2: legacy branch tax_rate dropped */;
        $serviceChargeRate = $setting->serviceChargeRate;
        $billIndex = (int) ($metadata['bill_index'] ?? 0);
        $peopleCount = max($billIndex + 1, (int) ($order->guest_count ?? 1));

        $allocationShape = [];
        foreach ($allocations as $alloc) {
            $allocationShape[] = [
                'item_id' => (string) $alloc['item_id'],
                'units' => (int) $alloc['units'],
            ];
        }

        $expectedTotal = $this->splitBillTotals->billTotalFor(
            (string) $order->id,
            $allocationShape,
            $billIndex,
            $roundingMode,
            $currencyCode,
            $taxRate,
            $serviceChargeRate,
            $peopleCount,
        );

        // Reconcile parity with pos-web. `computeBillTotal()` deliberately runs
        // `reconcile: false`, so it returns each bill's *natural* total. pos-web
        // (`split-by-items.ts`) forwards the rounding drift between Σ bill.total
        // and order.total_amount onto the LAST non-empty bill and tenders that
        // reconciled figure as `amount`. For that closing sub-check the natural
        // total therefore disagrees with what the cashier legitimately sends —
        // a difference that can exceed the 1-minor-unit tolerance and used to
        // trip a false `split_by_items_total_mismatch` (negative drift shrinks
        // the last bill below its natural total).
        //
        // When this payment fully allocates the order (i.e. it IS the closing
        // sub-check), the reconciled amount is exactly "order.total_amount minus
        // everything already tendered". Accept that figure as an alternative to
        // the natural total. Money stays bounded: the overpayment guard that
        // runs immediately after still caps Σ payments at order.total_amount.
        $newUnitsByItem = [];
        foreach ($allocations as $alloc) {
            $newItemId = (string) $alloc['item_id'];
            $newUnitsByItem[$newItemId] = ($newUnitsByItem[$newItemId] ?? 0) + (int) $alloc['units'];
        }

        $fullyAllocated = true;
        foreach ($order->items as $orderItem) {
            $statusRaw = $orderItem->status;
            $statusValue = is_object($statusRaw) && property_exists($statusRaw, 'value') ? $statusRaw->value : $statusRaw;
            if ($statusValue === 'voided') {
                continue;
            }
            $itemKey = (string) $orderItem->id;
            $claimedTotal = ($claimedByItem[$itemKey] ?? 0) + ($newUnitsByItem[$itemKey] ?? 0);
            if ($claimedTotal !== max(1, (int) $orderItem->quantity)) {
                $fullyAllocated = false;
                break;
            }
        }

        $tolerance = max(RoundingMode::step($roundingMode, $currencyCode), 0.01);
        $matchesNatural = abs($expectedTotal - $amount) <= $tolerance;

        $matchesReconciled = false;
        if ($fullyAllocated) {
            $priorPaid = 0.0;
            foreach ($existingClaims as $row) {
                if ($row->refund_of_id !== null || (float) $row->amount < 0) {
                    continue;
                }
                $priorPaid += (float) $row->amount;
            }
            $remaining = (float) ($order->total_amount ?? 0) - $priorPaid;
            $matchesReconciled = abs($remaining - $amount) <= $tolerance;
        }

        if (! $matchesNatural && ! $matchesReconciled) {
            abort(response()->json([
                'message' => 'Sub-check amount disagrees with the server-recomputed total. Refresh and recalculate.',
                'code' => 'split_by_items_total_mismatch',
                'expected_amount' => number_format($expectedTotal, 2, '.', ''),
                'given_amount' => number_format($amount, 2, '.', ''),
            ], 422));
        }
    }

    // =========================================================================
    //  Confirm
    // =========================================================================

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function mergeMetadata(OrderPayment $payment, array $metadata): OrderPayment
    {
        return DB::transaction(function () use ($payment, $metadata) {
            /** @var OrderPayment $payment */
            $payment = $this->ledgerWriter->lockById($payment->getKey());

            $metadata = array_filter($metadata, static fn ($value): bool => $value !== null);
            if ($metadata === []) {
                return $payment->load('paymentMethod');
            }

            return $this->ledgerWriter->updateRow($payment, [
                'metadata' => array_merge($payment->metadata ?? [], $metadata),
            ])->load('paymentMethod');
        });
    }

    public function attributeTillSession(OrderPayment $payment, ?string $tillSessionId): OrderPayment
    {
        return DB::transaction(function () use ($payment, $tillSessionId) {
            /** @var OrderPayment $payment */
            $payment = $this->ledgerWriter->lockById($payment->getKey());

            return $this->ledgerWriter->updateRow($payment, [
                'till_session_id' => $tillSessionId,
            ])->load('paymentMethod');
        });
    }

    public function confirm(OrderPayment $payment): OrderPayment
    {
        return DB::transaction(function () use ($payment) {
            // Re-fetch under a row lock — the bound model may be stale, and a
            // concurrent payments:expire-stale sweep could otherwise interleave
            // between our read and write (issue #532). Every other money
            // mutator in the repo re-locks the same way.
            /** @var OrderPayment $payment */
            $payment = $this->ledgerWriter->lockById($payment->getKey());

            $currentStatus = $payment->status instanceof PaymentStatusEnum
                ? $payment->status
                : PaymentStatusEnum::from($payment->status);

            if ($currentStatus !== PaymentStatusEnum::Pending) {
                abort(409, "Payment must be 'pending' to confirm. Current: {$currentStatus->value}");
            }

            $this->orchestrationCompat->finalizeLegacyConfirm($payment);

            // Revenue lands in whatever shift is open at CONFIRM time, not the
            // shift that was open when the pending row was created (issue #552).
            // A card/transfer created in shift A but approved after A settled
            // must NOT re-attribute revenue to the (already reconciled) shift A
            // — that retroactively mutates A's live-computed Z-report (reconcile
            // counts `succeeded` rows live; the Z-report/dashboard/history all
            // read it back live). Two cases:
            //   • A shift is open now  → re-stamp to it (revenue belongs there).
            //   • No shift is open now → DETACH (till_session_id = null) when the
            //     original stamp points at a TERMINAL shift. Leaving it on the
            //     settled/abandoned/expired shift is exactly the #552 leak: the
            //     flip to `succeeded` retro-grows that closed shift's gross. A
            //     null stamp keeps the payment out of every shift's reconcile set
            //     (no drawer was open to collect it), so no closed Z-report moves.
            $updates = [
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => now(),
            ];
            if ($payment->branch_id !== null) {
                $openSession = $this->tillSessions->currentForBranch($payment->branch_id)['open_session'] ?? null;
                if ($openSession !== null) {
                    $updates['till_session_id'] = $openSession->id;
                } elseif ($payment->till_session_id !== null && $this->stampedShiftIsTerminal($payment)) {
                    // No open shift and the payment is pinned to a closed one →
                    // detach so it can't retro-attribute into a settled Z-report.
                    $updates['till_session_id'] = null;
                }
            }

            $payment = $this->ledgerWriter->updateRow($payment, $updates);

            /** @var CustomerOrder $order */
            $order = $payment->customerOrder;

            $this->updateOrderPaymentCache($order);

            $order->refresh();

            if ($this->isOrderPaidInFull($order)) {
                $this->settleIfPaid($order, $payment->received_by_id, 'settle-confirm-'.$payment->id);
            }

            $payment->logAudit('payment_confirmed', ['amount' => $payment->amount]);

            return $payment->load('paymentMethod');
        });
    }

    /**
     * Is the shift the payment is currently stamped to already in a terminal
     * state (settled / abandoned / expired)? Used by confirm() to decide whether
     * a payment approved while no shift is open must be detached from its
     * (already-closed) shift instead of retro-attributing revenue to it (#552).
     *
     * A missing shift row is treated as terminal — there is nothing live to keep
     * the stamp anchored to.
     */
    private function stampedShiftIsTerminal(OrderPayment $payment): bool
    {
        $status = TillSession::query()
            ->whereKey($payment->till_session_id)
            ->value('status');

        if ($status === null) {
            return true;
        }

        $status = $status instanceof TillSessionStatusEnum
            ? $status
            : TillSessionStatusEnum::from($status);

        return ! in_array(
            $status,
            [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
            true,
        );
    }

    // =========================================================================
    //  Fail
    // =========================================================================

    /**
     * Mark a pending payment as failed (terminal decline / timeout / cancel).
     *
     * M12 (#555) — this used to live inline in the Workstation + Kiosk
     * controllers with a read-check-write against an UNLOCKED row. A concurrent
     * confirm() (which DOES lock) could flip the row to `succeeded` between the
     * controller's status read and its update, and the failing write would then
     * stomp `succeeded → failed` — an illegal transition that leaves the money
     * collected on the terminal but the ledger marked failed. Routing both
     * controllers through this locked mutator closes the race: confirm and fail
     * now serialise on the same row lock, so the loser observes the winner's
     * terminal status and 409s instead of overwriting it.
     *
     * `$metadata` (failure reason / terminal ref) is merged under the same lock
     * so the audit context is atomic with the status flip.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function fail(OrderPayment $payment, array $metadata = []): OrderPayment
    {
        return DB::transaction(function () use ($payment, $metadata) {
            /** @var OrderPayment $payment */
            $payment = $this->ledgerWriter->lockById($payment->getKey());

            $currentStatus = $payment->status instanceof PaymentStatusEnum
                ? $payment->status
                : PaymentStatusEnum::from($payment->status);

            if ($currentStatus !== PaymentStatusEnum::Pending) {
                abort(409, "Payment must be 'pending' to fail. Current: {$currentStatus->value}");
            }

            $this->orchestrationCompat->finalizeLegacyFail($payment);

            $updates = ['status' => PaymentStatusEnum::Failed->value];

            $metadata = array_filter($metadata, fn ($v) => $v !== null);
            if ($metadata !== []) {
                $updates['metadata'] = array_merge($payment->metadata ?? [], $metadata);
            }

            $payment = $this->ledgerWriter->updateRow($payment, $updates);

            // Recompute the order's paid_amount cache from the ledger. A pending
            // row never contributed to paid_amount (updateOrderPaymentCache only
            // sums succeeded + refunded), so this is a no-op on the happy path —
            // but it heals any drift and keeps the cache authoritative should the
            // set of counted statuses ever change.
            if ($order = $payment->customerOrder) {
                $this->updateOrderPaymentCache($order);
            }

            $payment->logAudit('payment_failed', ['amount' => $payment->amount]);

            return $payment->load('paymentMethod');
        });
    }

    // =========================================================================
    //  Refund
    // =========================================================================

    /**
     * @param  array{amount?: float|null, note?: string|null, reference_no?: string|null, idempotency_key?: string|null}  $data
     *                                                                                                                           `reference_no` is the workstation sync identity;
     *                                                                                                                           `idempotency_key` is the public POS replay identity.
     */
    public function refund(OrderPayment $payment, array $data): OrderPayment
    {
        return DB::transaction(function () use ($payment, $data) {
            /** @var OrderPayment $locked */
            $locked = $this->ledgerWriter->lockById($payment->id);

            // A response can be lost after this transaction commits. Replaying
            // the same key must return the original reversal before the status
            // guard below sees the source payment as already refunded. The row
            // lock serializes concurrent attempts for this source payment; the
            // per-order unique index is the final cross-process backstop.
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $existing = OrderPayment::query()
                    ->where('customer_order_id', $locked->customer_order_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ((string) $existing->refund_of_id !== (string) $locked->id) {
                        abort(409, 'Idempotency key was already used for another payment operation.');
                    }

                    return $existing->load('paymentMethod');
                }
            }

            $currentStatus = $locked->status instanceof PaymentStatusEnum
                ? $locked->status
                : PaymentStatusEnum::from($locked->status);

            if ($currentStatus !== PaymentStatusEnum::Succeeded) {
                abort(409, "Payment must be 'succeeded' to refund. Current: {$currentStatus->value}");
            }

            // The customer handed over sale + tip, so that whole figure is
            // refundable (#821 C1). Capping at `amount` alone made the tip
            // unreturnable: cancel the sale and the customer's ¥1,000 tip stayed
            // in the drawer with no way to give it back. Default (no amount sent)
            // refunds everything they paid.
            $saleAmount = (float) $locked->amount;
            $tipPaid = (float) $locked->tip_amount;
            $paidTotal = $saleAmount + $tipPaid;

            $refundAmount = isset($data['amount']) ? (float) $data['amount'] : $paidTotal;

            if ($refundAmount > $paidTotal) {
                abort(422, 'Refund amount cannot exceed what the customer paid (sale + tip).');
            }

            if ($refundAmount <= 0) {
                abort(422, 'Refund amount must be greater than zero.');
            }

            // plan-054 D5 — PayPay has no refund path wired. Without this guard a
            // staff refund click falls through to the ledger-only branch below:
            // the original flips to `refunded`, a negative row is written,
            // paid_amount drops, the invoice is voided and a 適格返還請求書 is
            // issued — for money that never left PayPay. It would also bypass the
            // live-refund kill switch and the per-refund cap, which live inside
            // the Stripe branch. Refuse loudly; the operator refunds on the PayPay
            // merchant portal and records it by hand.
            if ($this->isPayPayPayment($locked)) {
                abort(409, 'PayPay payments cannot be refunded here yet. Refund it on the PayPay merchant portal and record it manually.');
            }

            // Split the refund across the two columns the ledger tracks
            // separately: the sale is returned first, anything beyond it comes
            // out of the tip. Both are stored NEGATIVE so each nets against its
            // own column (revenue vs tips) instead of the tip silently vanishing.
            $saleRefund = min($refundAmount, $saleAmount);
            $tipRefund = $refundAmount - $saleRefund;

            // #548 — Stripe card payments must reverse the real charge. Call
            // the Refunds API while still holding the row lock so the flip to
            // `refunded` below and this external call are atomic against a
            // racing refund. A Stripe failure throws → the whole transaction
            // rolls back → no phantom "refunded" ledger row.
            //
            // BLOCKER (double real refund) — the idempotency key MUST be STABLE
            // across retries, not a fresh Str::uuid() per call. If any post-charge
            // step rolls back and the caller/queue retries this refund, a random
            // key would fire a SECOND real card refund. Deriving the key from the
            // payment id makes a retry hit Stripe's same-refund dedupe and return
            // the ORIGINAL refund object instead. In-app rules already allow
            // exactly one refund per payment (the original flips to `refunded`),
            // so one key per payment id is correct.
            $stripeRefundId = null;
            if ($this->isStripePayment($locked)) {
                // BLOCKER 2 — money safety gates. Both fire BEFORE the real
                // Refunds API call so no external money moves when they trip.
                //
                // (1) Kill-switch: live card refunds are OFF unless an operator
                // explicitly enables them. Cash / non-Stripe refunds bypass this
                // block entirely (they never reach here) because they move no
                // external money — only ledger rows.
                if (! config('payments.stripe_live_refunds_enabled', false)) {
                    abort(403, 'Live card refunds are disabled.');
                }

                // (3) Per-refund cap: a single card refund above the configured
                // ceiling is rejected. The cap is compared directly against the
                // refund amount in the order's own currency (minor-unit-agnostic).
                $maxCardRefund = (float) config('payments.max_card_refund_amount', 1_000_000);
                if ($refundAmount > $maxCardRefund) {
                    abort(422, "Card refund amount ({$refundAmount}) exceeds the maximum allowed per refund ({$maxCardRefund}).");
                }

                $stripeRefund = $this->stripeService()->refundPayment(
                    (string) $locked->reference_no,
                    $refundAmount,
                    'refund_'.$locked->id,
                );
                $stripeRefundId = (string) $stripeRefund->id;
            }

            // Mark the original payment as refunded
            $locked = $this->ledgerWriter->updateRow($locked, ['status' => PaymentStatusEnum::Refunded->value]);

            // Issue #523 — cross-session refund accounting. The refund row is
            // stamped to whatever shift is OPEN AT REFUND TIME, not the sale
            // shift. Cash physically leaves the drawer NOW, so this shift's
            // expected_cash must drop by the refunded amount; the original sale
            // stays counted against its own (possibly already-settled) shift so
            // refunding never retroactively shrinks a closed Z-report. Mirrors
            // the confirm() re-stamp rule (issue #552). If no shift is open we
            // leave it null — nothing better to attribute the outflow to.
            $refundTillSessionId = null;
            if ($payment->branch_id !== null) {
                $openSession = $this->tillSessions->currentForBranch($payment->branch_id)['open_session'] ?? null;
                $refundTillSessionId = $openSession?->id;
            }

            // #821 A4 (regression introduced by the settlement fix) — the reversal
            // MUST inherit the original's metadata, above all settles_payment_id.
            //
            // Debt-settlement rows are excluded from an order's paid_amount by
            // `whereNull('metadata->settles_payment_id')` (updateOrderPaymentCache,
            // and the overpay guard): the ¥5,000,000 a customer hands over to clear
            // an old debt is not payment for the ¥30,000 coffee it rides on. But the
            // reversal was written with metadata = null, so refunding that settlement
            // excluded the original and COUNTED the −5,000,000 mate: the coffee order
            // booked paid_amount = −5,000,000, and its overpay guard then happily
            // accepted another ¥5,030,000. Copying the metadata keeps the pair
            // symmetric — both excluded — so a refunded settlement nets to zero.
            $reversalMetadata = (array) ($locked->metadata ?? []);
            if ($stripeRefundId !== null) {
                $reversalMetadata['stripe_refund_id'] = $stripeRefundId;
            }

            // Create a new refund record with a negative amount
            $refundPayment = $this->ledgerWriter->createRow([
                'customer_order_id' => $locked->customer_order_id,
                'payment_method_id' => $locked->payment_method_id,
                'amount' => -$saleRefund,
                'tip_amount' => -$tipRefund,
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => now(),
                // A caller that owns an idempotency key (the workstation sends
                // its local refund_id) stamps it HERE, inside the transaction.
                // It used to be written by the caller with a second ->update()
                // after refund() returned: a crash in that gap left a refund row
                // with no key, and the retry — which looks the refund up BY that
                // key — would refund a second time.
                'reference_no' => $data['reference_no'] ?? $locked->reference_no,
                'idempotency_key' => $idempotencyKey,
                'note' => $data['note'] ?? null,
                'refund_of_id' => $locked->id,
                'till_session_id' => $refundTillSessionId,
                'received_by_id' => $locked->received_by_id,
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'brand_id' => $locked->brand_id,
                'metadata' => $reversalMetadata !== [] ? $reversalMetadata : null,
            ]);

            /** @var CustomerOrder $order */
            $order = $locked->customerOrder;

            $this->updateOrderPaymentCache($order);

            $locked->logAudit('payment_refunded', [
                'refund_payment_id' => $refundPayment->id,
                'refund_amount' => $refundAmount,
                'stripe_refund_id' => $stripeRefundId,
            ]);

            return $refundPayment->load('paymentMethod');
        });
    }

    /**
     * Sync a Stripe-originated refund into the ledger (#548, chiều B).
     *
     * Driven by the `charge.refunded` webhook — mirrors money that ALREADY
     * moved (dashboard refund OR the in-app path above). Never calls Stripe.
     *
     * Idempotency is keyed on `metadata.stripe_refund_id`:
     *  - the in-app path stamped the same Stripe refund id → this is a no-op;
     *  - a replayed webhook finds the row it wrote earlier → no-op;
     *  - a fresh dashboard refund → writes the negative ledger row + flips the
     *    original to `refunded`, exactly like an in-app refund would have.
     *
     * The amount is capped at the still-refundable balance so a partial in-app
     * refund followed by a dashboard top-up can't over-credit the ledger.
     *
     * @return OrderPayment|null the refund row (existing or new), or null when
     *                           no matching original payment / nothing to do.
     */
    public function syncStripeRefund(string $paymentIntentId, string $stripeRefundId, float $amount, ?string $note = null): ?OrderPayment
    {
        return DB::transaction(function () use ($paymentIntentId, $stripeRefundId, $amount, $note) {
            // Idempotency: this exact Stripe refund is already ledgered.
            $existing = $this->ledgerWriter->findByMetadataStripeRefundId($stripeRefundId);

            if ($existing !== null) {
                return $existing;
            }

            /** @var OrderPayment|null $original */
            $original = $this->ledgerWriter->findOriginalByReferenceNoForUpdate($paymentIntentId);

            if ($original === null) {
                Log::info('charge.refunded webhook: no matching original payment', [
                    'payment_intent' => $paymentIntentId,
                    'stripe_refund_id' => $stripeRefundId,
                ]);

                return null;
            }

            // BLOCKER (double real refund) — re-assert idempotency AFTER acquiring
            // the row lock. The unlocked `$existing` probe above races: two
            // overlapping charge.refunded webhooks for the SAME stripe_refund_id
            // can both find no row, then queue on lockForUpdate($original). Only
            // now, inside the serialized section, is it safe to conclude the id is
            // still unledgered — the loser of the lock race must re-check and bail,
            // otherwise it double-credits the ledger.
            $alreadyLedgered = $this->ledgerWriter->findByMetadataStripeRefundId($stripeRefundId);

            if ($alreadyLedgered !== null) {
                return $alreadyLedgered;
            }

            // Cap at the still-refundable balance — never over-credit the
            // ledger past the original charge.
            $alreadyRefunded = $this->ledgerWriter->sumAbsRefundAmountForOriginal($original->id);
            $refundable = (float) $original->amount - $alreadyRefunded;
            $creditAmount = min($amount, $refundable);

            if ($creditAmount <= 0) {
                return null;
            }

            if ($original->status !== PaymentStatusEnum::Refunded) {
                $original = $this->ledgerWriter->updateRow($original, ['status' => PaymentStatusEnum::Refunded->value]);
            }

            $refundPayment = $this->ledgerWriter->createRow([
                'customer_order_id' => $original->customer_order_id,
                'payment_method_id' => $original->payment_method_id,
                'amount' => -$creditAmount,
                'tip_amount' => 0,
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => now(),
                'reference_no' => $paymentIntentId,
                'note' => $note ?? 'stripe_dashboard_refund',
                'refund_of_id' => $original->id,
                'received_by_id' => $original->received_by_id,
                'organization_id' => $original->organization_id,
                'branch_id' => $original->branch_id,
                'brand_id' => $original->brand_id,
                'metadata' => ['stripe_refund_id' => $stripeRefundId],
            ]);

            $this->updateOrderPaymentCache($original->customerOrder);

            $original->logAudit('payment_refunded', [
                'refund_payment_id' => $refundPayment->id,
                'refund_amount' => $creditAmount,
                'stripe_refund_id' => $stripeRefundId,
                'source' => 'stripe_webhook',
            ]);

            return $refundPayment->load('paymentMethod');
        });
    }

    /**
     * A payment is a Stripe card charge when it was recorded against the
     * canonical `stripe` payment method AND carries a `pi_…` PaymentIntent
     * reference (both are stamped by OrderPaymentService::recordStripeWebhookPayment).
     */
    private function isStripePayment(OrderPayment $payment): bool
    {
        $reference = (string) $payment->reference_no;

        if (! str_starts_with($reference, 'pi_')) {
            return false;
        }

        $payment->loadMissing('paymentMethod');

        return optional($payment->paymentMethod)->code === 'stripe';
    }

    /**
     * plan-054 — a PayPay QR payment, identified the same way its Stripe twin is.
     *
     * Both signals are required. The reference prefix alone would also match a
     * QR attempt reference stamped by some future provider, and the method code
     * alone would match a manually-keyed PayPay tender at the counter — which is
     * ledger-only money that legitimately reverses without calling anyone.
     */
    private function isPayPayPayment(OrderPayment $payment): bool
    {
        if (! PayPayQrCodeClient::isQrMerchantPaymentId((string) $payment->reference_no)) {
            return false;
        }

        $payment->loadMissing('paymentMethod');

        return optional($payment->paymentMethod)->code === PayPayCanonicalPaymentMethodProvisioner::CODE;
    }

    /**
     * Refund a payment (in-app / staff-initiated).
     *
     * #548 — for a **Stripe card payment** this now reverses the REAL card
     * charge via the Refunds API BEFORE writing the ledger, so the book entry
     * and the money move together (option a). The whole thing runs under a
     * `lockForUpdate()` on the payment row: a concurrent second refund click
     * blocks, then sees `status = refunded` and 409s — it never fires a second
     * Stripe refund. Non-Stripe payments (cash / manual card) keep the
     * ledger-only behaviour unchanged.
     *
     * The negative refund row records `metadata.stripe_refund_id` so the
     * `charge.refunded` webhook (syncStripeRefund) recognises this refund as
     * already-ledgered and stays a no-op — the two paths share one key.
     *
     * @param  array{amount?: float, note?: string}  $data
     */
    /**
     * Resolve StripePaymentService lazily so non-Stripe refunds (cash / manual
     * card) never touch the Stripe SDK at all. Since #1232 the client itself is
     * built on first API call, so an unset secret only bites a caller that
     * really talks to Stripe. Tests bind a mock via the container.
     */
    private function stripeService(): StripePaymentService
    {
        return app(StripePaymentService::class);
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Recalculate paid_amount from the ledger and settle through the canonical
     * OrderMutationFacade when fully paid. Stripe webhooks call this after
     * recordStripeWebhookPayment so close side effects match cash/card confirm().
     */
    public function syncLedgerCacheAndSettleIfPaid(
        CustomerOrder $order,
        ?string $actorId = null,
        ?string $idempotencyKey = null,
        ?string $referenceNo = null,
    ): void {
        $this->updateOrderPaymentCache($order);
        $order->refresh();

        if ($this->isOrderPaidInFull($order)) {
            $this->settleIfPaid($order, $actorId, $idempotencyKey);

            return;
        }

        if ($referenceNo === null) {
            return;
        }

        $payment = OrderPayment::query()
            ->where('customer_order_id', $order->id)
            ->where('reference_no', $referenceNo)
            ->first();

        if ($payment !== null) {
            OrderPaymentRecorded::dispatch($order, $payment);
        }
    }

    /**
     * Insert (or skip if already-recorded) an OrderPayment row for a succeeded
     * Stripe PaymentIntent webhook / synchronous confirm path.
     *
     * @param  array<string, mixed>|null  $metadata  Normalized intent metadata (split_count, item_allocations, …).
     * @return bool True when a new payment row was written.
     */
    public function recordStripeWebhookPayment(
        CustomerOrder $order,
        string $intentId,
        float $amount,
        string $flow,
        ?array $metadata = null,
        ?string $paymentAttemptId = null,
    ): bool {
        $existing = OrderPayment::query()
            ->where('customer_order_id', $order->id)
            ->where('reference_no', $intentId)
            ->first();

        // #1125 option B — an awaiting-async placeholder row settles HERE: the
        // pending row (voucher printed / funds in transit) flips to succeeded
        // with the money's arrival timestamp. Any other status = duplicate.
        if ($existing !== null && $existing->status === PaymentStatusEnum::Pending) {
            $attempt = $paymentAttemptId === null
                ? null
                : PaymentAttempt::query()->with('connectionOption')->find($paymentAttemptId);

            $mergedMetadata = array_merge((array) ($existing->metadata ?? []), is_array($metadata) ? $metadata : []);
            $mergedMetadata['async_settled'] = true;

            $this->ledgerWriter->updateRow($existing, [
                'status' => PaymentStatusEnum::Succeeded->value,
                'amount' => $amount,
                'paid_at' => now(),
                'payment_attempt_id' => $paymentAttemptId ?? $existing->payment_attempt_id,
                'gateway_connection_id' => $attempt?->connection_id ?? $existing->gateway_connection_id,
                'gateway_option_id' => $attempt?->connectionOption?->option_id ?? $existing->gateway_option_id,
                'metadata' => $mergedMetadata,
            ]);

            return true;
        }

        if ($existing !== null) {
            return false;
        }

        $paymentMethod = app(StripeCanonicalPaymentMethodProvisioner::class)
            ->resolveForOrganization((string) $order->organization_id);

        // This path is only reached from the customer-web Stripe webhook, so the
        // channel is fixed. Stamp the server-owned column, not metadata (see the
        // create() path — #1058/#1059).
        $metadata = is_array($metadata) ? $metadata : [];

        // Plan-048 T2.3 — the prepared attempt already carries the resolved
        // connection/option identity (policy-backed or bootstrap); mirror it
        // onto the ledger row so Stripe payments stop losing gateway identity.
        $attempt = $paymentAttemptId === null
            ? null
            : PaymentAttempt::query()->with('connectionOption')->find($paymentAttemptId);

        $this->ledgerWriter->createRow([
            'amount' => $amount,
            'tip_amount' => 0,
            'status' => PaymentStatusEnum::Succeeded->value,
            'paid_at' => now(),
            'reference_no' => $intentId,
            'payment_method_id' => $paymentMethod->id,
            'payment_attempt_id' => $paymentAttemptId,
            'gateway_connection_id' => $attempt?->connection_id,
            'gateway_option_id' => $attempt?->connectionOption?->option_id,
            'customer_order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'brand_id' => $order->brand_id,
            'organization_id' => $order->organization_id,
            // #2863 — NULL, KHÔNG phải UUID toàn số 0.
            //
            // `received_by_id` là cột "actor" đa hình theo quy ước: khoản qua POS
            // mang user id (`OrderPaymentController:124`), khoản qua
            // workstation/kiosk/handy mang DEVICE id (`PaymentController:170`,
            // `HandyController:443`, `KioskController:381`). Webhook Stripe không
            // có cả hai — không người bấm, không thiết bị của quán — và schema
            // `schemas/Backend/Product/OrderPayment.yaml` đã khai `nullable: true`
            // ĐÚNG vì ca này ("online auto-confirm payments have no human staff").
            //
            // Hằng `00000000-0000-0000-0000-000000000000` viết ở đây mâu thuẫn với
            // chính chú thích đó, và nó nói dối một cách trông đáng tin: đo trên
            // production ngày 2026-08-13 có **145/414** khoản mang giá trị này
            // trong khi **không một hàng `users` nào** mang id đó. Ai join sang
            // `users` được 0 hàng và tưởng dữ liệu hỏng; ai đọc thẳng thì tưởng có
            // người thu tiền. NULL nói đúng sự thật: không có ai cả.
            //
            // 145 hàng cũ viết lại MỘT LẦN bằng
            // `2026_08_15_120000_manual_migration_null_out_payment_sentinel_actor`
            // (ruling #2188 — backfill lần cuối, không dựng nhánh tương thích).
            'received_by_id' => null,
            'note' => $flow === 'split' ? 'split_bill' : 'full_payment',
            'metadata' => $metadata !== [] ? $metadata : null,
            'channel' => PaymentChannelEnum::CustomerWeb->value,
        ]);

        return true;
    }

    /**
     * plan-054 — the single funnel every PayPay QR payment lands through.
     *
     * Modelled on StripePaymentService::markOrderPaidFromIntent, NOT on
     * recordStripeWebhookPayment: that method is a bare SELECT-then-INSERT which
     * is only safe because every one of its callers already holds the order row
     * lock. This one has two callers in different processes — the provider-event
     * queue worker and the customer's status poll — so it owns the whole
     * transaction itself.
     *
     * Guards, in order, and each one exists for a case that actually happens:
     *   1. row lock          — the two writers race by construction
     *   2. idempotency       — webhook and poll both report the same COMPLETED
     *   3. order state       — a QR outlives the order it was minted for
     *   4. currency          — the branch currency can be edited mid-flight
     *   5. overpayment       — the counter can take cash while the QR is live
     *
     * Money that passes a guard but cannot be ledgered is NOT silently kept: the
     * amount is returned so the caller can hand it back once out of the lock. We
     * never call a provider API while holding the order row.
     *
     * Nhận **id**, không nhận model. #1666/#962 tách bản này ra cho đường khôi
     * phục webhook (`ProviderRetrievalRecoveryService`) vốn chỉ có id; #1594 xoá
     * nốt cái vỏ nhận `CustomerOrder` sau khi hai chỗ gọi còn lại (mint QR và
     * sweep) cũng thôi cầm model. Cái vỏ đó chỉ đọc `$order->id` rồi khoá lại
     * đúng hàng ấy — nó không bao giờ là một đường thứ hai.
     *
     * @param  float  $amount  The amount PayPay says it took — never order.total_amount.
     * @return array{recorded: bool, stranded_amount: float|null, reason: string|null}
     */
    public function recordPayPayPaymentByOrderId(
        string $orderId,
        string $merchantPaymentId,
        float $amount,
        string $currency,
        ?string $paymentAttemptId = null,
    ): array {
        $outcome = ['recorded' => false, 'stranded_amount' => null, 'reason' => null];

        DB::transaction(function () use ($orderId, $merchantPaymentId, $amount, $currency, $paymentAttemptId, &$outcome): void {
            $lockedOrder = CustomerOrder::lockForUpdate()->find($orderId);

            if ($lockedOrder === null) {
                $outcome['reason'] = 'order_missing';

                return;
            }

            // Idempotency FIRST, under the lock, so the overpayment re-sum below
            // never counts this payment's own row. Both keys are checked: the
            // idempotency_key is the DB-enforced one (unique per order), while
            // reference_no also catches a row written before that stamping existed.
            $existing = OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->where(function ($query) use ($merchantPaymentId): void {
                    $query->where('idempotency_key', $merchantPaymentId)
                        ->orWhere('reference_no', $merchantPaymentId);
                })
                ->first();

            if ($existing !== null) {
                $outcome['reason'] = 'already_recorded';

                return;
            }

            // A QR lives ~5 minutes and the order can die inside that window —
            // expiry sweep, staff void, or the counter closing it by cash. The
            // Stripe funnel has no equivalent check because its expiry sweep
            // cancels the intent at the provider first; nothing does that for a
            // QR, so the money can arrive for an order that is already gone.
            if (in_array($lockedOrder->status, [CustomerOrderStatusEnum::Voided, CustomerOrderStatusEnum::Expired], true)) {
                Log::warning('paypay_payment_for_dead_order', [
                    'order_id' => (string) $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'order_status' => $lockedOrder->status->value,
                    'merchant_payment_id' => $merchantPaymentId,
                    'amount' => $amount,
                ]);

                $outcome['stranded_amount'] = $amount;
                $outcome['reason'] = 'order_not_payable';

                return;
            }

            $expectedCurrency = $this->resolveOrderCurrencyForPayPay($lockedOrder);

            if (strtoupper($currency) !== $expectedCurrency) {
                Log::error('paypay_currency_mismatch', [
                    'order_id' => (string) $lockedOrder->id,
                    'merchant_payment_id' => $merchantPaymentId,
                    'charged_currency' => strtoupper($currency),
                    'expected_currency' => $expectedCurrency,
                ]);

                $outcome['stranded_amount'] = $amount;
                $outcome['reason'] = 'currency_mismatch';

                return;
            }

            $ledgerPaid = (float) OrderPayment::query()
                ->where('customer_order_id', $lockedOrder->id)
                ->whereIn('status', [
                    PaymentStatusEnum::Succeeded->value,
                    PaymentStatusEnum::Refunded->value,
                ])
                ->sum('amount');
            $currentPaid = max($ledgerPaid, (float) $lockedOrder->paid_amount);
            $outstanding = (float) $lockedOrder->total_amount - $currentPaid;

            if ($amount - $outstanding > self::PAYPAY_OVERPAY_EPSILON) {
                // The customer already paid at the counter while their QR was
                // still live. Recording this would push collected past total and
                // hide a real overpayment, so refuse and hand the money back.
                Log::warning('paypay_payment_would_overpay', [
                    'order_id' => (string) $lockedOrder->id,
                    'order_code' => $lockedOrder->order_code,
                    'merchant_payment_id' => $merchantPaymentId,
                    'amount' => $amount,
                    'already_paid' => $currentPaid,
                    'total_amount' => (float) $lockedOrder->total_amount,
                ]);

                $outcome['stranded_amount'] = $amount;
                $outcome['reason'] = 'overpayment';

                return;
            }

            $attempt = $paymentAttemptId === null
                ? null
                : PaymentAttempt::query()->with('connectionOption')->find($paymentAttemptId);

            $paymentMethod = app(PayPayCanonicalPaymentMethodProvisioner::class)
                ->resolveForOrganization((string) $lockedOrder->organization_id);

            $this->ledgerWriter->createRow([
                'amount' => $amount,
                'tip_amount' => 0,
                'status' => PaymentStatusEnum::Succeeded->value,
                'paid_at' => now(),
                'reference_no' => $merchantPaymentId,
                // The DB backstop: order_payments is unique on
                // (customer_order_id, idempotency_key), and nothing enforces
                // uniqueness on reference_no. Stamping the merchant payment id
                // here is what makes the race above fail loudly instead of
                // producing a second row.
                'idempotency_key' => $merchantPaymentId,
                'payment_method_id' => $paymentMethod->id,
                'payment_attempt_id' => $paymentAttemptId,
                'gateway_connection_id' => $attempt?->connection_id,
                'gateway_option_id' => $attempt?->connectionOption?->option_id,
                'customer_order_id' => $lockedOrder->id,
                'branch_id' => $lockedOrder->branch_id,
                'brand_id' => $lockedOrder->brand_id,
                'organization_id' => $lockedOrder->organization_id,
                // #2863 — ví PayPay tự xác nhận, không có người thu. Lý do đầy đủ
                // vì sao NULL chứ không phải UUID toàn số 0: xem
                // `recordStripeWebhookPayment()` ngay trên.
                'received_by_id' => null,
                'note' => 'paypay_qr',
                // Dine-in splits the bill, so this row has to say WHICH share it
                // is. Without it a by-items PayPay payment records as a bare
                // amount, and `splitByItemsPreview` then refuses the next payer
                // outright (`split_by_items_mode_locked`) because the order now
                // carries a row using "another split mode" — while the dishes the
                // first payer settled never disable in the bill either.
                'metadata' => $this->payPayPaymentMetadata($lockedOrder, $merchantPaymentId, $amount),
                // Server-owned column, never metadata (#1058/#1059). Also what
                // keeps this money out of the cashier's shift reconciliation —
                // no drawer collected it.
                'channel' => PaymentChannelEnum::CustomerWeb->value,
            ]);

            $outcome['recorded'] = true;

            $this->syncLedgerCacheAndSettleIfPaid(
                $lockedOrder,
                idempotencyKey: 'settle-paypay-'.$merchantPaymentId,
                referenceNo: $merchantPaymentId,
            );
        });

        return $outcome;
    }

    /**
     * Same slack as the Stripe funnel: absorbs float noise on two-decimal
     * currencies without masking a real overpayment. PayPay is JPY-only, so the
     * comparison is already exact in practice.
     */
    private const PAYPAY_OVERPAY_EPSILON = 0.01;

    /**
     * What share of the bill this PayPay payment settled.
     *
     * Assembled at settlement rather than at mint because PayPay's create call
     * carries no metadata field of ours — the payer's declared split is parked
     * against the merchant payment id by `PayPayQrSplitIntent` and read back
     * here, so all three funnels that reach this method (the customer's poll,
     * the webhook recovery path and the stale-attempt sweeper) produce the same
     * row without any of them having to know about splits.
     *
     * Null when there is nothing to say, preserving the "no split → metadata
     * null" contract (#1058).
     *
     * @return array<string, mixed>|null
     */
    private function payPayPaymentMetadata(CustomerOrder $order, string $merchantPaymentId, float $amount): ?array
    {
        $declared = PayPayQrSplitIntent::toPaymentMetadata(
            PayPayQrSplitIntent::recall($merchantPaymentId),
            $amount,
        );

        // The even-split backfill runs even when nothing was declared: the
        // headcount is already on the order (customer-web POSTs /split-mode
        // before any payment), so a cache eviction still produces the hard lock
        // /split-status needs. Only per-dish attribution is unrecoverable.
        return $this->backfillByPeopleSplitMetadata($order, $declared === [] ? null : $declared, $amount);
    }

    /**
     * #406 — Auto-stamp split_count + amount_per_person on by_people payments so
     * /split-status returns consistent state regardless of which client created
     * the payment (the Stripe path already stamps these via
     * createSplitPaymentIntent; kiosk / pos / workstation / PayPay paths did
     * not). Values the caller passed explicitly are never overwritten — this
     * only fills the gaps from the order.
     *
     * Reasoning: customer-web's PaymentView writes split_mode +
     * split_people_count to customer_orders via /split-mode BEFORE any payment.
     * Once a payment arrives — regardless of path — the resulting
     * OrderPayment.metadata MUST carry the same split_count so subsequent
     * guests' /split-status read sees a hard lock instead of an indefinite
     * tentative_split_count.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function backfillByPeopleSplitMetadata(CustomerOrder $order, ?array $metadata, float $amount): ?array
    {
        if ($order->split_mode !== OrderSplitMode::Even->value
            || $order->split_people_count === null
            || (int) $order->split_people_count < 2) {
            return $metadata;
        }

        $metadata = is_array($metadata) ? $metadata : [];

        if (! isset($metadata['split_count'])) {
            $metadata['split_count'] = (int) $order->split_people_count;
        }

        if (! isset($metadata['amount_per_person'])) {
            // Already in major units, matching the Stripe path which stores a
            // major-unit amount_per_person too — see
            // StripePaymentService::createSplitPaymentIntent.
            $metadata['amount_per_person'] = $amount;
        }

        if (! isset($metadata['split_mode'])) {
            $metadata['split_mode'] = OrderSplitMode::Even->value;
        }

        return $metadata;
    }

    /**
     * The order's priced currency, read the same way the Stripe funnel reads it
     * (shop_order_settings.currency_code) so both guards agree on what "the
     * order's currency" means. Falls back to JPY because PayPay settles in JPY
     * only — a branch with no setting cannot have priced in anything else.
     */
    private function resolveOrderCurrencyForPayPay(CustomerOrder $order): string
    {
        $code = app(BranchCurrency::class)->codeFor((string) $order->branch_id);

        return strtoupper((string) ($code ?: 'JPY'));
    }

    /**
     * #1125 option B — awaiting-async placeholder for a Stripe intent parked
     * in `processing` / voucher-displayed state. Status PENDING so no money
     * reader counts it (paid_amount/KPI sum succeeded+refunded only); staff
     * and reconciliation SEE the in-flight voucher instead of an order that
     * looks untouched. Idempotent per intent id (any-status row short-circuits;
     * the succeeded flip happens in recordStripeWebhookPayment).
     */
    public function recordAsyncPendingPayment(
        // #1643 — THU HẸP THUẦN: tham số này chưa bao giờ được dùng như một
        // model. Thân method chỉ lấy `$order->id` để KHOÁ LẠI dòng đơn
        // (`lockForUpdate()->find(...)`) và đọc mọi thứ từ dòng đã khoá đó —
        // nhận model chỉ khiến hai module Payments phải cầm hàng của Ordering
        // để rồi vứt đi.
        string $orderId,
        string $intentId,
        float $amount,
        string $asyncMethod,
        string $intentStatus,
        ?string $expiresAt = null,
    ): bool {
        // Rào cho đúng cái bẫy mà việc thu hẹp này tạo ra: file này KHÔNG bật
        // `strict_types`, mà `Illuminate\...\Model::__toString()` trả JSON — nên
        // một chỗ gọi quên sửa vẫn truyền được model vào tham số `string` này,
        // `find()` nhận một chuỗi JSON, trả null, và method **im lặng trả false**.
        // Đã xảy ra thật khi dựng #1643: hai test đỏ ở assertion cuối chứ không
        // ở lời gọi, nên dấu vết chỉ ra sai chỗ. Ném ra thì lỗi nằm đúng chỗ gây.
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException(
                'recordAsyncPendingPayment() nhận id đơn (uuid), không nhận model.'
            );
        }

        return DB::transaction(function () use ($orderId, $intentId, $amount, $asyncMethod, $intentStatus, $expiresAt): bool {
            $lockedOrder = CustomerOrder::lockForUpdate()->find($orderId);
            if ($lockedOrder === null) {
                return false;
            }

            $exists = OrderPayment::query()
                ->where('reference_no', $intentId)
                ->exists();
            if ($exists) {
                return false;
            }

            $paymentMethod = app(StripeCanonicalPaymentMethodProvisioner::class)
                ->resolveForOrganization((string) $lockedOrder->organization_id);

            $this->ledgerWriter->createRow([
                'amount' => $amount,
                'tip_amount' => 0,
                'status' => PaymentStatusEnum::Pending->value,
                'paid_at' => null,
                'reference_no' => $intentId,
                'payment_method_id' => $paymentMethod->id,
                'customer_order_id' => $lockedOrder->id,
                'branch_id' => $lockedOrder->branch_id,
                'brand_id' => $lockedOrder->brand_id,
                'organization_id' => $lockedOrder->organization_id,
                // #2863 — Konbini/銀行振込 chờ khách đi nộp tiền; lúc ghi dòng này
                // chưa ai thu và cũng sẽ không có ai thu. Lý do đầy đủ vì sao NULL:
                // xem `recordStripeWebhookPayment()` ở trên.
                //
                // Đây là dòng `pending` DUY NHẤT trong ba chỗ, nên là chỗ duy nhất
                // đi được tới `confirm()`/`fail()` → `finalizeLegacy*()` →
                // `OrderPaymentOrchestrationCompat::mutationContextFromPayment()`,
                // nơi có `$payment->received_by_id ?? throw`. Nó KHÔNG tới được:
                // `shouldRouteLegacyConfirm()` đòi `payment_attempt_id !== null`, mà
                // dòng này cố ý không mang attempt (Stripe chưa xác nhận gì). Hai
                // dòng kia sinh ra đã `succeeded` nên confirm/fail 409 trước đó.
                'received_by_id' => null,
                'note' => 'async_pending',
                'metadata' => array_filter([
                    'async_pending' => true,
                    'async_method' => $asyncMethod,
                    'async_intent_status' => $intentStatus,
                    'async_expires_at' => $expiresAt,
                ], fn ($v) => $v !== null),
                'channel' => PaymentChannelEnum::CustomerWeb->value,
            ]);

            Log::channel('payment_orchestration')->info('stripe_async_payment_pending', [
                'customer_order_id' => (string) $lockedOrder->id,
                'payment_intent_id' => $intentId,
                'async_method' => $asyncMethod,
                'amount' => $amount,
                'expires_at' => $expiresAt,
            ]);

            return true;
        });
    }

    /**
     * #1125 option B — terminal failure of an awaiting-async intent
     * (payment_failed webhook, voucher canceled/expired, superseded by a new
     * intent). Flips the pending row to FAILED and releases the order back to
     * payable by clearing its intent pointer when it still points here — the
     * guest can immediately mint a fresh intent. Idempotent: no pending row →
     * nothing to do.
     */
    public function failAsyncPendingPayment(string $intentId, string $reason): string
    {
        return DB::transaction(function () use ($intentId, $reason): string {
            /** @var OrderPayment|null $row */
            $row = OrderPayment::query()
                ->where('reference_no', $intentId)
                ->where('status', PaymentStatusEnum::Pending->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return 'ignored_no_pending_row';
            }

            $metadata = (array) ($row->metadata ?? []);
            $metadata['async_failure_reason'] = $reason;

            $this->ledgerWriter->updateRow($row, [
                'status' => PaymentStatusEnum::Failed->value,
                'metadata' => $metadata,
            ]);

            $order = CustomerOrder::lockForUpdate()->find($row->customer_order_id);
            if ($order !== null && (string) $order->stripe_payment_intent_id === $intentId) {
                $this->orderService->stampStripeIntent(new StampOrderStripeIntentCommand(
                    $this->mutationContextFromOrder($order, 'async-release:'.$intentId),
                    $order->id,
                    null,
                ));
            }

            Log::channel('payment_orchestration')->warning('stripe_async_payment_failed', [
                'customer_order_id' => (string) $row->customer_order_id,
                'payment_intent_id' => $intentId,
                'reason' => $reason,
            ]);

            return 'applied';
        });
    }

    private function settleIfPaid(CustomerOrder $order, ?string $actorId = null, ?string $idempotencyKey = null): void
    {
        if (! $this->isOrderPaidInFull($order)) {
            return;
        }

        $context = new MutationContext(
            organizationId: $order->organization_id,
            actorId: $actorId,
            correlationId: (string) Str::uuid(),
            idempotencyKey: $idempotencyKey ?? 'settle-'.$order->id.'-'.(string) Str::uuid(),
            expectedVersion: 1,
        );

        $this->orderService->settleIfPaid(new SettleOrderIfPaidCommand($context, $order->id));
    }

    /**
     * plan-055 T4.1 / T6.1 / T6.3 (#1823) — the one fork where a payment that
     * names no gateway option is either waved through or refused.
     *
     * `PaymentPolicySubmission::fromPaymentData()` returns null when the caller
     * omitted `gateway_option_id`, and until now that silently skipped the
     * policy check entirely — for POS, kiosk and workstation alike. So a method
     * the shop disabled in policy was still payable by any client that simply
     * did not mention it. This is where that stops being invisible.
     *
     *   flag OFF (default) → let it through, log `payment_policy_option_missing`
     *   flag ON            → 422 POLICY_OPTION_REQUIRED, no ledger row written
     *
     * The OFF branch is not a placeholder: it is the measurement Gate 3 of
     * plan-055 exits on. The log line carries transport + device + branch + org
     * precisely so the rollout produces an EXACT list of who would break on
     * flip, instead of an estimate.
     *
     * ⚠️ DO NOT ENABLE THE FLAG YET. The offline-replay boundary (plan-055
     * T5.1) is unresolved: Cloud cannot currently distinguish a payment taken
     * offline yesterday and synced today from one taken online just now — both
     * arrive on `POST /workstation/payments` with no marker. Turning this on
     * before that is settled REFUSES MONEY THAT IS ALREADY IN THE TILL, which
     * cannot be un-refused: the cash does not come back, it just leaves an
     * orphaned order and a shift that no longer reconciles. See
     * `plans/plan-055/NOTES.md`.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleMissingPolicyOption(array $data, CustomerOrder $order, PaymentMethod $paymentMethod): void
    {
        $context = [
            'order_id' => (string) $order->id,
            'organization_id' => (string) $order->organization_id,
            'branch_id' => (string) $order->branch_id,
            'transport' => $data['orchestrator_transport'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ];

        // plan-055 T5.1 (#1826) — an order that Cloud itself stamped as an
        // offline replay is waived, ALWAYS, flag or not.
        //
        // The money was taken in the shop while the workstation was offline; it
        // is already in the till. Refusing it at sync time does not un-take it —
        // it leaves an orphaned order and a shift that no longer reconciles.
        //
        // The stamp is trustworthy because CLOUD writes it, inside
        // `insertOfflineReplay()` after `assertTrusted()`. A device cannot set
        // it by sending a field, which is exactly why the marker is not a
        // client-supplied `taken_offline_at`: that would let any device waive
        // the check on every payment forever — the hole this plan exists to
        // close, wearing a different name.
        if ($order->offline_replayed_at !== null) {
            Log::channel('payment_orchestration')->warning('payment_policy_replay_bypass', $context);

            return;
        }

        // plan-055 #1831 — internal tenders are outside gateway policy BY
        // CONSTRUCTION, so requiring a policy option for them is a category
        // error: it demands an identity that cannot exist in the thing being
        // checked.
        //
        // `PaymentPolicyEvaluationService::effectiveOptions()` — the source of
        // both the published snapshot AND what the validator reads — never calls
        // the internal-tender enricher; those options are appended at the
        // controller layer only. So an internal option is never IN the snapshot,
        // and a client that names one is refused with "Submitted gateway option
        // is absent from the referenced policy revision". That is why pos-web
        // deliberately sends no identity for cash.
        //
        // Without this, flipping the Gate 6 flag would refuse EVERY CASH
        // PAYMENT — the most common tender in both markets.
        //
        // This does not take away a shop's ability to switch cash off: that is
        // `PaymentMethod.is_active`, enforced a few lines below.
        //
        // Server-owned, like the replay stamp above: the method is resolved from
        // the DB by id, so a device cannot claim internal-ness. Naming a cash
        // method only ever records a cash payment; it routes no money through
        // any gateway.
        // This fork runs BEFORE the `is_active` guard, so a shop that switched
        // cash off still gets a refusal — measured: 422, no ledger row — but
        // with `refresh_payment_options` instead of `payment_method_inactive`.
        // No money moves either way; the cost is a confusing message at the till,
        // and only from Gate 6 onward. Left as-is deliberately rather than
        // reordering a money fork; tracked here so it is a choice, not an
        // oversight.
        if ($this->isInternalTender($paymentMethod, $order)) {
            Log::channel('payment_orchestration')->info('payment_policy_internal_tender_exempt', $context + [
                'payment_method_id' => (string) $paymentMethod->id,
                'payment_method_code' => (string) $paymentMethod->code,
            ]);

            return;
        }

        if (! (bool) config('payments.policy_enforcement.required', false)) {
            Log::channel('payment_orchestration')->warning('payment_policy_option_missing', $context);

            return;
        }

        throw new PaymentConfigurationException(
            'This payment must reference the effective gateway option it was taken under.',
            'POLICY_OPTION_REQUIRED',
            422,
            false,
            'refresh_payment_options',
            $context,
        );
    }

    /**
     * Was the SUBMITTED option one of the internal-catalog options?
     *
     * The method being an internal tender is not enough on the identity fork: a
     * client can name a cash method while submitting a real gateway option id,
     * and that payment does carry gateway identity the policy must rule on.
     */
    private function isInternalCatalogOption(?string $optionId): bool
    {
        if ($optionId === null || $optionId === '') {
            return false;
        }

        return PaymentGatewayOption::query()
            ->whereKey($optionId)
            ->whereHas('provider', function ($query): void {
                $query->where('code', PaymentGatewayProviderCodeEnum::Internal->value);
            })
            ->exists();
    }

    /**
     * Is this payment an internal tender (cash / standalone card terminal /
     * on-account) rather than gateway-routed money?
     *
     * Delegates to `PosEffectivePaymentOptionEnricher`, which owns the ONE
     * definition — the internal-provider catalog mapped to legacy method codes.
     * A second predicate here (on `type`, or a hard-coded code list) would drift
     * from the option list the POS actually shows, and drift means either
     * refusing cash or waiving a gateway payment.
     *
     * Fails CLOSED: a branch that cannot be resolved is not treated as internal.
     */
    private function isInternalTender(PaymentMethod $paymentMethod, CustomerOrder $order): bool
    {
        $branch = $order->branch;

        if (! $branch instanceof Branch) {
            return false;
        }

        return in_array(
            (string) $paymentMethod->id,
            $this->internalTenderCatalog->internalTenderMethodIds($branch),
            true,
        );
    }

    /**
     * plan-055 T3.4 (#1834) — enforce, EXCEPT for identity that only reached us
     * through the legacy-name compatibility layer while enforcement is still
     * optional.
     *
     * Aliasing the legacy names (#1829, #1830) fixed a real drop, but it also
     * made the policy check run for clients that were silently exempt before.
     * That is a Gate 3 behaviour change, and T3.4 forbids exactly that:
     * "Server: accept missing as before. Do NOT change behaviour at this gate."
     *
     * Measured, on a branch with no published policy revision — the state of
     * most of production until Gate 2 runs: the identical workstation request
     * goes 201-with-a-payment-row before the alias and
     * 422-"No published payment policy revision exists for this branch"-with-none
     * after it. That is refusing real money ahead of both the observation gate
     * and the flip, which is the ordering the plan opens by warning about:
     * inverting the gates costs money, not time.
     *
     * So a refusal on the aliased path becomes an OBSERVATION until the Gate 6
     * flag flips. That is not a hole being re-opened — it is the same softness
     * these clients already had, and it feeds Gate 4 the one thing it needs:
     * the exact list of who will fail at the flip.
     *
     * Deliberately NOT softened by the ALIAS rule: pos-web talking straight to
     * Cloud. It has sent canonical names and been enforced for real since
     * plan-047, so its requests never carry the marker.
     *
     * There are now TWO transport-blind widenings on this fork, not one — an
     * earlier version of this paragraph named only the first and was corrected
     * once already for exactly that omission. The second is the internal-tender
     * waiver (#1831): a cash sale is a cash sale on any surface, so it waives a
     * canonical pos-web-direct client too. Narrower than it sounds — it needs
     * BOTH a server-resolved internal tender method AND an internal-catalog
     * option id — but it IS a widening, stated here rather than left to be found.
     *
     * The offline-replay waiver below is the first, and is deliberately
     * TRANSPORT-BLIND — it fires before the marker check, so it can waive a
     * canonical client too. That is intentional (T5.1 applies it transport-blind
     * for the same reason) but it IS a widening of a previously hard-enforced
     * path, so it is stated rather than left to be discovered: an order stamped
     * `offline_replayed_at` whose balance is later taken at the POS is waived.
     * Narrow, not client-reachable — only `insertOfflineReplay()` writes the
     * stamp, after `assertTrusted()` — and recorded in the ⛔ section of
     * `docs/guide/payment-go-live.md` as part of the unresolved offline
     * boundary. (Caught in review; the previous wording claimed this branch
     * never sees a canonical client, which stopped being true.)
     *
     * @param  array<string, mixed>  $data
     * @param  PaymentMethod  $paymentMethod  server-resolved; decides the internal-tender exemption
     */
    private function assertPolicyAllowedOrObserve(
        PaymentPolicySubmission $submission,
        array $data,
        CustomerOrder $order,
        PaymentMethod $paymentMethod,
    ): void {
        try {
            $this->policySubmissionValidator->assertNewPaymentAllowed($submission);
        } catch (PaymentConfigurationException $e) {
            // Only the two policy verdicts are observable. Anything else thrown
            // from inside the evaluator is a genuine configuration fault and
            // must still stop the payment — catching the CLASS would silently
            // log-and-allow a future failure that has nothing to do with policy
            // staleness. (Caught in review.)
            if (! in_array($e->errorCode, PaymentPolicySubmissionValidator::EMITTED_ERROR_CODES, true)) {
                throw $e;
            }

            // plan-055 T5.1 (#1826) — the same waiver its sibling
            // `handleMissingPolicyOption()` carries, and for the same reason.
            //
            // It matters MORE here, not less: before the alias a replayed
            // offline order carried no identity and took the sibling path, so
            // the waiver covered it. Now it carries identity and lands here
            // instead — so without this the Gate 6 flip would refuse money that
            // is already in the till, which is exactly what T5.2 shipped a
            // contract against. (Caught in review; the first version of this
            // method left the waiver behind.)
            if ($order->offline_replayed_at !== null) {
                Log::channel('payment_orchestration')->warning('payment_policy_replay_bypass', [
                    'order_id' => (string) $order->id,
                    'organization_id' => (string) $order->organization_id,
                    'branch_id' => (string) $order->branch_id,
                    'transport' => $data['orchestrator_transport'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    // The sibling fork cannot carry this — it only runs when
                    // there is no identity at all. So it distinguishes the two
                    // waivers in the log, and Gate 4 wants the id anyway.
                    'gateway_option_id' => $submission->gatewayOptionId,
                ]);

                return;
            }

            // plan-055 #1831 — the SAME internal-tender rule as the sibling fork.
            //
            // Caught in review: the first version exempted only the fork where
            // NO identity arrives, which is pos-web's shape. The kiosk sends an
            // identity for every option it displays — including the internal
            // ones the very same enricher appended to its list — so a kiosk cash
            // sale lands HERE instead, with an option id that can never be in
            // the snapshot, and is refused with PAYMENT_POLICY_STALE. Kiosk cash
            // was therefore still broken while the plan file said the obstacle
            // was removed.
            //
            // Server-owned exactly as in the sibling: the method is resolved
            // from the DB by id, so a device cannot claim internal-ness.
            //
            // BOTH conditions, not just the method (caught by the acceptance
            // suite going red): keying on the method alone waives
            // "cash method + GATEWAY option id", which is exactly B11 — a shop
            // disables a gateway option and the client submits it anyway. The
            // submitted option must itself be an internal-catalog option for the
            // category argument to hold; otherwise real gateway identity is in
            // play and policy must decide.
            if ($this->isInternalTender($paymentMethod, $order)
                && $this->isInternalCatalogOption($submission->gatewayOptionId)) {
                Log::channel('payment_orchestration')->info('payment_policy_internal_tender_exempt', [
                    'order_id' => (string) $order->id,
                    'organization_id' => (string) $order->organization_id,
                    'branch_id' => (string) $order->branch_id,
                    'transport' => $data['orchestrator_transport'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'payment_method_id' => (string) $paymentMethod->id,
                    'payment_method_code' => (string) $paymentMethod->code,
                    'submitted_gateway_option_id' => $submission->gatewayOptionId,
                ]);

                return;
            }

            // ĐÃ GỠ #2410 — miễn trừ "danh tính tới qua tên trường cũ".
            //
            // Chỗ này từng nuốt lỗi policy khi danh tính chỉ tới được nhờ lớp
            // alias (plan-055 T3.4 / #1834): alias làm phép kiểm policy CHẠY cho
            // những client trước đó được miễn trong im lặng, và Gate 3 cấm đúng
            // việc đổi hành vi kiểu đó. Lớp alias đã xoá, nên điều kiện không
            // bao giờ đúng nữa và nhánh này là mã chết.
            //
            // Đo trước khi gỡ (production, 7 ngày tới 17/08): **252** payment
            // qua workstation, **0** lượt alias thắng. Mẫu số khác 0 nên số 0
            // đó là kết quả, không phải phép đo không chạy.
            throw $e;
        }
    }

    /**
     * Recalculate and persist paid_amount and total_tip on the order
     * from the net of all money-bearing payments.
     *
     * A refund flips the original payment to `refunded` AND inserts a
     * negative `succeeded` row (see refund()). Summing only `succeeded`
     * therefore drops the original's positive amount while keeping the
     * negative offset — double-subtracting the refund and driving
     * paid_amount negative (issue #528). Both the original (`refunded`)
     * and the negative offset (`succeeded`) must be counted so that
     * paid_amount = collected − refunded.
     */
    private function updateOrderPaymentCache(CustomerOrder $order): void
    {
        $this->orderService->refreshPaymentCache(new RefreshOrderPaymentCacheCommand(
            $this->mutationContextFromOrder($order),
            $order->id,
        ));
    }

    private function mutationContextFromOrder(CustomerOrder $order, ?string $idempotencyKey = null): MutationContext
    {
        return new MutationContext(
            (string) $order->organization_id,
            null,
            'order-payment-cache:'.$order->id,
            $idempotencyKey ?? 'order-payment-cache:'.$order->id,
            expectedVersion: 1,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mutationContextFromPaymentData(array $data, CustomerOrder $order): MutationContext
    {
        return new MutationContext(
            (string) $data['organization_id'],
            (string) $data['received_by_id'],
            'order-payment:'.$order->id,
            isset($data['idempotency_key']) && is_string($data['idempotency_key'])
                ? $data['idempotency_key']
                : 'order-payment:'.$order->id,
            expectedVersion: 1,
        );
    }

    // ─── #1123 (D2) — Stripe chargeback / dispute lifecycle ────────────────

    /**
     * Mirror a Stripe dispute event onto the ledger. The bank moves the money
     * unilaterally — our job is to make the books follow:
     *
     *   charge.dispute.created          → flag the payment (no money moved yet)
     *   charge.dispute.funds_withdrawn  → CONTRA-REVENUE row (mirror of
     *                                     syncStripeRefund: negative amount,
     *                                     refund_of_id, original → Refunded)
     *   charge.dispute.funds_reinstated → positive re-credit row (dispute won)
     *   charge.dispute.closed           → lost ⇒ ensure the withdrawal is
     *                                     ledgered; won ⇒ ensure reinstatement
     *
     * Idempotent per (dispute id, kind) via metadata, double-checked after the
     * row lock exactly like syncStripeRefund. Every branch leaves an alert on
     * the payment_orchestration channel — a dispute is always ops-worthy.
     */
    public function syncStripeDispute(
        string $eventType,
        string $paymentIntentId,
        string $disputeId,
        float $amount,
        ?string $disputeStatus = null,
    ): ?OrderPayment {
        return DB::transaction(function () use ($eventType, $paymentIntentId, $disputeId, $amount, $disputeStatus) {
            $original = $this->ledgerWriter->findOriginalByReferenceNoForUpdate($paymentIntentId);

            if ($original === null) {
                Log::channel('payment_orchestration')->warning('stripe_dispute_no_matching_payment', [
                    'event_type' => $eventType,
                    'payment_intent' => $paymentIntentId,
                    'stripe_dispute_id' => $disputeId,
                ]);

                return null;
            }

            return match ($eventType) {
                'charge.dispute.created' => $this->flagDisputeOpened($original, $disputeId, $disputeStatus),
                'charge.dispute.funds_withdrawn' => $this->ledgerDisputeWithdrawal($original, $disputeId, $amount),
                'charge.dispute.funds_reinstated' => $this->ledgerDisputeReinstatement($original, $disputeId),
                'charge.dispute.closed' => $disputeStatus === 'lost'
                    ? $this->ledgerDisputeWithdrawal($original, $disputeId, $amount)
                    : $this->ledgerDisputeReinstatement($original, $disputeId),
                default => null,
            };
        });
    }

    private function flagDisputeOpened(OrderPayment $original, string $disputeId, ?string $disputeStatus): OrderPayment
    {
        $metadata = is_array($original->metadata) ? $original->metadata : [];
        $metadata['stripe_dispute_id'] = $disputeId;
        $metadata['stripe_dispute_status'] = $disputeStatus ?? 'needs_response';

        $original = $this->ledgerWriter->updateRow($original, ['metadata' => $metadata]);

        $original->logAudit('payment_disputed', [
            'stripe_dispute_id' => $disputeId,
            'dispute_status' => $metadata['stripe_dispute_status'],
        ]);
        Log::channel('payment_orchestration')->warning('stripe_dispute_opened', [
            'payment_id' => $original->id,
            'customer_order_id' => $original->customer_order_id,
            'stripe_dispute_id' => $disputeId,
            'dispute_status' => $metadata['stripe_dispute_status'],
        ]);
        $this->notifyDisputeManagers($original, $disputeId, 'opened', (float) $original->amount);

        return $original;
    }

    private function ledgerDisputeWithdrawal(OrderPayment $original, string $disputeId, float $amount): ?OrderPayment
    {
        // Idempotency re-checked INSIDE the serialized section (the caller holds
        // the original's row lock) — same double-check as syncStripeRefund.
        $existing = $this->findDisputeRow($disputeId, 'withdrawal');
        if ($existing !== null) {
            return $existing;
        }

        // Never over-credit past what the charge still holds. If a voluntary
        // refund already returned everything, the bank's withdrawal has no
        // ledger headroom — flag loudly instead of double-reversing.
        $alreadyReversed = $this->ledgerWriter->sumAbsRefundAmountForOriginal($original->id);
        $creditAmount = min($amount, (float) $original->amount - $alreadyReversed);

        if ($creditAmount <= 0) {
            Log::channel('payment_orchestration')->warning('stripe_dispute_withdrawal_already_reversed', [
                'payment_id' => $original->id,
                'stripe_dispute_id' => $disputeId,
                'dispute_amount' => $amount,
                'already_reversed' => $alreadyReversed,
            ]);

            return null;
        }

        if ($original->status !== PaymentStatusEnum::Refunded) {
            $original = $this->ledgerWriter->updateRow($original, ['status' => PaymentStatusEnum::Refunded->value]);
        }

        $contra = $this->ledgerWriter->createRow([
            'customer_order_id' => $original->customer_order_id,
            'payment_method_id' => $original->payment_method_id,
            'amount' => -$creditAmount,
            'tip_amount' => 0,
            'status' => PaymentStatusEnum::Succeeded->value,
            'paid_at' => now(),
            'reference_no' => $original->reference_no,
            'note' => 'stripe_dispute_chargeback',
            'refund_of_id' => $original->id,
            'received_by_id' => $original->received_by_id,
            'organization_id' => $original->organization_id,
            'branch_id' => $original->branch_id,
            'brand_id' => $original->brand_id,
            'metadata' => ['stripe_dispute_id' => $disputeId, 'dispute_kind' => 'withdrawal'],
        ]);

        $this->updateOrderPaymentCache($original->customerOrder);

        $original->logAudit('payment_dispute_funds_withdrawn', [
            'contra_payment_id' => $contra->id,
            'withdrawn_amount' => $creditAmount,
            'stripe_dispute_id' => $disputeId,
        ]);
        Log::channel('payment_orchestration')->warning('stripe_dispute_funds_withdrawn', [
            'payment_id' => $original->id,
            'customer_order_id' => $original->customer_order_id,
            'stripe_dispute_id' => $disputeId,
            'withdrawn_amount' => $creditAmount,
        ]);
        $this->notifyDisputeManagers($original, $disputeId, 'funds_withdrawn', $creditAmount);

        return $contra;
    }

    private function ledgerDisputeReinstatement(OrderPayment $original, string $disputeId): ?OrderPayment
    {
        $withdrawal = $this->findDisputeRow($disputeId, 'withdrawal');

        // Dispute won BEFORE any funds were withdrawn → nothing to re-credit;
        // just clear the flag so the payment reads clean again.
        if ($withdrawal === null) {
            $metadata = is_array($original->metadata) ? $original->metadata : [];
            $metadata['stripe_dispute_status'] = 'won';
            $this->ledgerWriter->updateRow($original, ['metadata' => $metadata]);

            return null;
        }

        $existing = $this->findDisputeRow($disputeId, 'reinstatement');
        if ($existing !== null) {
            return $existing;
        }

        // Positive re-credit. Deliberately NOT a refund_of_id row: the abs-sum
        // refundable guard must not shrink further (staying conservative can
        // only under-refund later, never over-refund).
        $recredit = $this->ledgerWriter->createRow([
            'customer_order_id' => $original->customer_order_id,
            'payment_method_id' => $original->payment_method_id,
            'amount' => abs((float) $withdrawal->amount),
            'tip_amount' => 0,
            'status' => PaymentStatusEnum::Succeeded->value,
            'paid_at' => now(),
            'reference_no' => $original->reference_no,
            'note' => 'stripe_dispute_reinstated',
            'received_by_id' => $original->received_by_id,
            'organization_id' => $original->organization_id,
            'branch_id' => $original->branch_id,
            'brand_id' => $original->brand_id,
            'metadata' => [
                'stripe_dispute_id' => $disputeId,
                'dispute_kind' => 'reinstatement',
                'reinstates_payment_id' => (string) $withdrawal->id,
            ],
        ]);

        // The withdrawal was this dispute's doing — if it was the only reversal
        // on the payment, restore the original to Succeeded.
        $otherReversals = $this->ledgerWriter->sumAbsRefundAmountForOriginal($original->id)
            - abs((float) $withdrawal->amount);
        if ($otherReversals <= 0 && $original->status === PaymentStatusEnum::Refunded) {
            $original = $this->ledgerWriter->updateRow($original, ['status' => PaymentStatusEnum::Succeeded->value]);
        }

        $this->updateOrderPaymentCache($original->customerOrder);

        $original->logAudit('payment_dispute_funds_reinstated', [
            'recredit_payment_id' => $recredit->id,
            'stripe_dispute_id' => $disputeId,
        ]);
        Log::channel('payment_orchestration')->info('stripe_dispute_funds_reinstated', [
            'payment_id' => $original->id,
            'stripe_dispute_id' => $disputeId,
        ]);
        $this->notifyDisputeManagers($original, $disputeId, 'funds_reinstated', abs((float) $withdrawal->amount));

        return $recredit;
    }

    /**
     * #1123 — in-app alert to the shop's managers for each dispute phase.
     * Best-effort by design (mirrors ExpiryAlertService): a notification
     * failure — no manager assigned to the branch, template missing — must
     * never fail the webhook whose ledger work already committed.
     */
    private function notifyDisputeManagers(OrderPayment $original, string $disputeId, string $phase, float $amount): void
    {
        try {
            $brand = Brand::query()->find($original->brand_id);
            $branch = Branch::query()->find($original->branch_id);
            if ($brand === null || $branch === null) {
                return;
            }

            $order = $original->customerOrder;

            app(NotificationDispatcher::class)->toRole(
                new NotificationRequest(
                    type: 'payment.disputed',
                    params: [
                        'order_code' => (string) ($order?->order_code ?? $original->customer_order_id),
                        'amount' => (string) $amount,
                        'phase' => $phase,
                        'dispute_status' => $phase,
                    ],
                    organizationId: (string) $original->organization_id,
                    subject: $original,
                    idempotencyKey: "payment.disputed:{$disputeId}:{$phase}",
                    priority: 'high',
                ),
                role: 'shop-manager',
                scopeKey: 'branch_id',
                scopeId: (string) $branch->getKey(),
                brand: $brand,
            );
        } catch (\Throwable $e) {
            Log::channel('payment_orchestration')->info('stripe_dispute_notification_skipped', [
                'payment_id' => $original->id,
                'stripe_dispute_id' => $disputeId,
                'phase' => $phase,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function findDisputeRow(string $disputeId, string $kind): ?OrderPayment
    {
        return OrderPayment::query()
            ->where('metadata->stripe_dispute_id', $disputeId)
            ->where('metadata->dispute_kind', $kind)
            ->first();
    }
}
