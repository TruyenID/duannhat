<?php

namespace App\Services\Customer;

use App\Models\Branch;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Payment\Gateway\PayPay\PayPayLifecycleMapper;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\PayPay\PayPayQrSplitIntent;
use App\Services\Payment\Orchestration\Internal\PayPayCanonicalPaymentMethodProvisioner;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use App\Support\CurrencyMinorUnit;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Mints and reads the PayPay QR a customer scans to pay for their own order.
 *
 * Sibling of StripePaymentService: it talks to the provider directly and bridges
 * to a PaymentAttempt, rather than driving the gateway through the orchestrator —
 * that glue does not exist for any provider today.
 *
 * Only ONE code per order is ever scannable. A call that mints invalidates
 * whatever was outstanding first — two live codes for one bill is how an
 * overpayment happens with nobody doing anything wrong.
 *
 * A call does NOT always mint, though. When the order already has a live code
 * that still collects exactly what is outstanding, it is handed back unchanged:
 * a page reload re-POSTs this endpoint, and treating that as a new request
 * killed the QR the guest had open in the PayPay app and restarted their
 * countdown. The resumed url comes from our own cache, never from asking PayPay
 * to repeat a merchant payment id — that is a provider behaviour not worth
 * betting money on. A cache miss simply mints, which is always safe.
 */
final class PayPayPaymentService
{
    /**
     * How much life a cached code must have left before it is worth resuming.
     *
     * A code within a few seconds of lapsing is worse than no code at all: the
     * guest scans it, PayPay refuses, and the failure looks like ours.
     */
    private const RESUME_MIN_SECONDS_LEFT = 30;

    public function __construct(
        private readonly PayPayQrCodeClient $qrCodes,
        private readonly PayPayCustomerWebBootstrap $bootstrap,
        private readonly OrderPaymentOrchestrationCompat $orchestration,
        private readonly PayPayCanonicalPaymentMethodProvisioner $paymentMethods,
        private readonly PayPayAvailabilityService $availability,
        private readonly OrderPaymentService $orderPayments,
        // #1603 — cổng ĐỌC của Ordering. Có mặt để đường khoá dưới đây lấy được
        // giá trị sau khi khoá mà không phải tự chạm `CustomerOrder`.
        private readonly OrderQueryPort $orders,
        private readonly PayPayLifecycleMapper $mapper = new PayPayLifecycleMapper,
    ) {}

    /**
     * @param  float|null  $requestedAmount  The payer's own share. Null means "whatever is
     *                                       still outstanding". Split-bill callers MUST pass
     *                                       their slice: the first of four payers would
     *                                       otherwise be handed a QR for the entire bill.
     * @param  array<string, mixed>|null  $splitPayload  How the payer arrived at that share
     *                                                   (`split_type`, `split_count`,
     *                                                   `item_allocations`). Held against the
     *                                                   merchant payment id so the settlement
     *                                                   funnel can attribute the money to the
     *                                                   dishes or the headcount it was
     *                                                   collected for — PayPay's create call
     *                                                   carries no metadata of our own.
     * @return array{qr_url: string, deeplink: string|null, merchant_payment_id: string, amount: float, expires_at: string|null, expires_in_seconds: int|null}
     */
    public function createQrCode(OrderSnapshot $order, ?float $requestedAmount = null, ?array $splitPayload = null): array
    {
        $this->assertPayable($order);

        $split = PayPayQrSplitIntent::normalize($splitPayload);

        $refs = $this->bootstrap->resolveForOrder(new OrderRef($order->aggregateId(), $order->organizationId(), $order->brandId(), $order->branchId()));
        $connection = PaymentGatewayConnection::query()->findOrFail($refs['connectionId']);
        $connectionData = GatewayConnectionDataFactory::fromModel($connection);
        $method = $this->paymentMethods->resolveForOrganization($order->organizationId());

        // A reload is not a new request for a code. Without this, every refresh
        // of the pay page killed the QR the guest may have been holding in the
        // PayPay app and issued another, restarting the countdown at five
        // minutes; ten refreshes also spent the whole per-order mint allowance
        // and locked them out.
        $resumed = $this->resumeOutstandingQr($order, $requestedAmount, $split);

        if ($resumed !== null) {
            return $resumed;
        }

        $this->invalidateOutstandingQr($order, $connectionData);

        // The amount is decided and committed to an attempt under the order lock,
        // so a concurrent payment cannot slip between the outstanding calculation
        // and the code that promises to collect it.
        [$attemptId, $merchantPaymentId, $amount] = DB::transaction(function () use ($order, $requestedAmount, $refs, $method): array {
            // #1603 — khoá VÀ đọc qua cổng của Ordering. `findForSettlement` đã
            // làm đúng việc này từ #1544 (`lockForUpdate()->first()` trên model
            // builder), nên không cần dựng thêm đường khoá thứ hai; nó chỉ mang
            // tên hẹp hơn thực tế.
            //
            // Ngữ nghĩa giữ nguyên hai điểm: model builder ⇒ đơn XOÁ MỀM không
            // khớp (giống `findOrFail` cũ, KHÁC `OrderRowLock` vốn cố ý dùng
            // query thô), và không tìm thấy thì ném — chỉ khác chỗ ném là ở đây.
            $locked = $this->orders->findForSettlement($order->organizationId(), $order->aggregateId())
                // #1594 — trước đây là `ModelNotFoundException` mang tên class
                // model. Cùng một kết quả quan sát được: controller bắt
                // `\Throwable` (chỉ `PayPayUnavailable` mới ra 422), và không
                // chỗ nào trên đường này bắt riêng `ModelNotFoundException`.
                ?? throw new RuntimeException('Order '.$order->aggregateId().' vanished before its PayPay QR could be minted.');

            $outstanding = round($locked->totalAmount() - $locked->paidAmount(), 2);

            if ($outstanding <= 0) {
                throw new PayPayUnavailable('This order has nothing left to pay.');
            }

            $amount = $requestedAmount === null ? $outstanding : round($requestedAmount, 2);

            if ($amount <= 0 || $amount > $outstanding) {
                throw new PayPayUnavailable('The requested amount does not match what is outstanding.');
            }

            try {
                $prepared = $this->orchestration->preparePayPayQrAttempt(
                    $locked,
                    $refs['connectionId'],
                    // The CATALOG option id. Passing the connection_option row id
                    // here made every mint fail the authority check — it looks the
                    // value up as `option_id`, not as the row's own key.
                    $refs['optionId'],
                    $refs['policyRevision'],
                    (int) round($amount),
                    'JPY',
                    (string) $method->id,
                );
            } catch (PayPayUnavailable $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // Authority/policy refusals arrive as InvalidArgumentException.
                // They mean "this order cannot be paid with PayPay", which is a
                // 422 the guest can act on — not a 500 that looks like an outage.
                MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_prepare_refused', [
                    'order_id' => $order->aggregateId(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                throw new PayPayUnavailable('PayPay is not available for this order.');
            }

            if ($prepared === null) {
                // Stripe tolerates a null prepare because its ledger write keys on
                // the intent id. PayPay's webhook matches on the attempt, so a QR
                // minted without one could never be credited.
                throw new PayPayUnavailable('PayPay payments are disabled for this transport.');
            }

            return [$prepared['attemptId'], $prepared['merchantPaymentId'], $amount];
        });

        // The attempt exists BEFORE the code does, so a scan can always be matched
        // back. The reverse order leaves an orphan QR whose payment arrives as
        // `paypay_no_matching_attempt` — an event marked succeeded while the money
        // is never ledgered.
        try {
            $code = $this->qrCodes->create(
                $connectionData,
                $merchantPaymentId,
                (int) round($amount),
                'JPY',
                'Order '.$order->orderCode(),
                null,
                'paypay:qr:'.$merchantPaymentId,
            );
        } catch (Throwable $exception) {
            // Delete first, cancel second. Once the attempt is terminal a webhook
            // for this code resolves to `paypay_ignored_terminal`, which is marked
            // succeeded and drops the money silently — so close the window at
            // PayPay while the attempt can still receive it.
            // Không truyền `code_id` được: create vừa ném nên chưa có session
            // nào để nhớ nó. Giữ nguyên lời gọi để không đổi hành vi đường này —
            // nó vốn best-effort — nhưng đừng trông đợi nó xoá được gì.
            $this->qrCodes->delete($connectionData, $merchantPaymentId, 'paypay:qr:cleanup:'.$merchantPaymentId);
            $this->orchestration->abandonPayPayQrAttempt($order, $attemptId, $merchantPaymentId);

            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_create_failed', [
                'order_id' => $order->aggregateId(),
                'attempt_id' => $attemptId,
                'merchant_payment_id' => $merchantPaymentId,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        // R24: never show a code as living longer than the order it collects
        // for. PayPay picks ~5 minutes and offers no way to shorten it, so an
        // order due in 60 seconds would otherwise display a five-minute
        // countdown, and a guest who trusted it would scan at minute two into a
        // payment nothing will accept.
        $expiresAt = $this->clampToOrderDeadline($code['expires_at'], $order);

        $this->rememberSession($merchantPaymentId, [
            'qr_url' => $code['url'],
            'deeplink' => $code['deeplink'],
            'expires_at' => $expiresAt,
            'amount' => $amount,
            // #1737 — PayPay xoá mã theo `codeId` của NÓ, không theo
            // `merchantPaymentId` của mình. Hai thứ khác nhau, và `codeId` chỉ
            // xuất hiện đúng một lần: trong phản hồi của `create`. Trước đây nó
            // bị bỏ ngay tại đây, nên mọi lời gọi `delete()` đều đưa nhầm
            // `merchantPaymentId` và PayPay 404 — tức `deleteQRCode` CHƯA BAO
            // GIỜ xoá được gì, kể cả trên đường mint. Lời hứa "Only ONE code per
            // order is ever scannable" ở docblock class vì thế mới chỉ đúng
            // trong DB của mình, chưa đúng ở phía PayPay.
            'code_id' => $code['code_id'],
            // Not read by the resume path off this key — `PayPayQrSplitIntent`
            // owns that, on its own longer-lived key. Kept alongside so the
            // display session and the attribution cannot be reasoned about
            // separately when debugging one code.
            'split_fingerprint' => PayPayQrSplitIntent::fingerprint($split),
        ]);

        // After the code exists, so a mint that failed at PayPay leaves no
        // attribution behind for the next one to inherit.
        PayPayQrSplitIntent::remember($merchantPaymentId, $split);

        return [
            'qr_url' => $code['url'],
            'deeplink' => $code['deeplink'],
            'merchant_payment_id' => $merchantPaymentId,
            'amount' => $amount,
            'expires_at' => $expiresAt === null ? null : now()->setTimestamp($expiresAt)->toIso8601String(),
            // Server-anchored: a client with a skewed clock would otherwise show a
            // code that "expired" hours ago. Same lesson as plan-031's
            // seconds_until_due.
            'expires_in_seconds' => $expiresAt === null ? null : max(0, $expiresAt - now()->getTimestamp()),
        ];
    }

    /**
     * PayPay is the only source of truth for whether the money moved; the local
     * attempt state is a cache of the last thing we heard.
     *
     * @return array{status: string, is_fully_paid: bool, order_status: string, expires_in_seconds: int|null, merchant_payment_id: string|null}
     */
    public function syncStatus(OrderSnapshot $order): array
    {
        $attempt = $this->liveAttempt($order);

        if ($attempt === null) {
            return $this->statusPayload($this->reread($order), 'NOT_FOUND');
        }

        $connection = PaymentGatewayConnection::query()->findOrFail($attempt->connection_id);
        $merchantPaymentId = (string) $attempt->provider_object_id;

        $details = $this->qrCodes->retrieve(
            GatewayConnectionDataFactory::fromModel($connection),
            $merchantPaymentId,
            'paypay:qr:status:'.$merchantPaymentId,
        );

        if ($this->mapper->mapQrPaymentState($details['status']) === PaymentAttemptStateEnum::Succeeded) {
            $currency = strtoupper((string) ($details['currency'] ?? 'JPY'));

            $this->orderPayments->recordPayPayPaymentByOrderId(
                $order->aggregateId(),
                $merchantPaymentId,
                // What PayPay says it took, never the order total. Converted the
                // same way the webhook path converts it: both write the same
                // money to the same row, so a divergence here would book two
                // different amounts depending on which one arrived first. The
                // exponent is 0 for JPY, so this is identity today — it exists so
                // the two paths cannot silently disagree if PayPay ever settles
                // in anything else.
                $this->toMajorUnits((int) ($details['amount'] ?? 0), $currency),
                $currency,
                (string) $attempt->id,
            );
        }

        $expiresAt = $details['expires_at'] ?? $this->recallExpiry($merchantPaymentId);

        return $this->statusPayload(
            $this->reread($order),
            $details['status'],
            $expiresAt === null ? null : max(0, $expiresAt - now()->getTimestamp()),
            $merchantPaymentId,
        );
    }

    /**
     * The earlier of the code's own expiry and the order's payment deadline.
     *
     * Only the displayed deadline moves. The code stays live at PayPay until
     * PayPay retires it — deliberately, because shortening the window the
     * customer sees is safe while shortening the window the provider honours
     * would strand a payment already in flight.
     */
    private function clampToOrderDeadline(?int $expiresAt, OrderSnapshot $order): ?int
    {
        $dueAt = $order->paymentDueAt()?->getTimestamp();

        if ($dueAt === null) {
            return $expiresAt;
        }

        return $expiresAt === null ? $dueAt : min($expiresAt, $dueAt);
    }

    /**
     * Remember when a minted code lapses, so the poll can answer the question the
     * provider will not.
     *
     * `/v2/codes/payments/{mpid}` returns payment details and carries no
     * `expiryDate` — only the create response does. Without this, a guest who
     * reloads the page polls and is told `expires_in_seconds: null`, so the
     * countdown has nothing to anchor on and the browser is back to guessing with
     * its own clock: the exact failure `expires_in_seconds` exists to prevent.
     *
     * A cache rather than a column because it is genuinely derived, disposable
     * state — losing it costs a re-mint, never a wrong number, and no money
     * decision reads it. The amount is held alongside so a resume can prove the
     * bill has not moved; the url and deeplink so a reload can be handed back the
     * code it already had. Held past the code's own life so a lapsed code can
     * still report "expired" rather than "unknown".
     */
    private function rememberSession(string $merchantPaymentId, array $session): void
    {
        Cache::put(self::sessionCacheKey($merchantPaymentId), $session, now()->addMinutes(30));
    }

    /**
     * @return array{qr_url: string, deeplink: string|null, expires_at: int|null, amount: float}|null
     */
    private function recallSession(string $merchantPaymentId): ?array
    {
        $cached = Cache::get(self::sessionCacheKey($merchantPaymentId));

        return is_array($cached) && isset($cached['qr_url']) ? $cached : null;
    }

    /**
     * #1737 — `codeId` của PayPay cho một mã, hoặc null nếu không nhớ được.
     *
     * PayPay xoá theo id CỦA NÓ; `merchantPaymentId` là id của mình và đưa nó
     * cho `deleteQRCode` chỉ nhận về 404. Session cache là nơi duy nhất giữ
     * `codeId` (create trả về đúng một lần). Mất cache ⇒ null ⇒ chỗ gọi bỏ qua
     * việc xoá thay vì gọi sai, và sweeper vẫn là lưới.
     */
    private function recallCodeId(string $merchantPaymentId): ?string
    {
        $session = $this->recallSession($merchantPaymentId);
        $codeId = $session['code_id'] ?? null;

        return is_string($codeId) && $codeId !== '' ? $codeId : null;
    }

    private function recallExpiry(string $merchantPaymentId): ?int
    {
        $expiresAt = $this->recallSession($merchantPaymentId)['expires_at'] ?? null;

        // `is_numeric`, not `is_int`: the Redis store hands the timestamp back as
        // the string '1785321763' while the array store used in tests keeps it an
        // int. An `is_int` check therefore passed every test and returned null
        // against every real deployment.
        return is_numeric($expiresAt) ? (int) $expiresAt : null;
    }

    private static function sessionCacheKey(string $merchantPaymentId): string
    {
        return 'paypay:qr-session:'.$merchantPaymentId;
    }

    private function toMajorUnits(int $minorAmount, string $currency): float
    {
        $exponent = CurrencyMinorUnit::exponent($currency);

        return round($minorAmount / (10 ** $exponent), $exponent);
    }

    /**
     * Đọc lại đơn qua cổng — chỗ đứng của `$order->refresh()` cũ.
     *
     * KHÔNG khoá: cả ba chỗ gọi chỉ cần con số mới nhất để TRẢ VỀ hoặc để quyết
     * "có resume mã cũ không", và resume sai chỉ dẫn tới mint lại (đường mint tự
     * lấy khoá). Đường ghi tiền vẫn đi `findForSettlement`.
     *
     * Ném khi không còn hàng, y như `refresh()` cũ ném `ModelNotFoundException`
     * trên một model đã biến mất — im lặng trả về ảnh chụp cũ sẽ báo cho khách
     * một trạng thái không còn tồn tại.
     */
    private function reread(OrderSnapshot $order): OrderSnapshot
    {
        return $this->orders->findById($order->organizationId(), $order->aggregateId())
            ?? throw new RuntimeException('Order '.$order->aggregateId().' vanished while its PayPay QR was being read.');
    }

    private function assertPayable(OrderSnapshot $order): void
    {
        // #1594 — `Branch` là TenancyKernel (hạ tầng dùng chung), không phải
        // model của Ordering; tra theo `branch_id` của ảnh chụp cho đúng hàng mà
        // quan hệ `$order->branch` cũ trả về.
        $branch = Branch::query()->find($order->branchId());

        if ($branch === null || ! $this->availability->forBranch($branch)['enabled']) {
            throw new PayPayUnavailable('PayPay is not available for this branch.');
        }

        if (in_array($order->status(), [
            CustomerOrderStatusEnum::Closed->value,
            CustomerOrderStatusEnum::Voided->value,
            CustomerOrderStatusEnum::Expired->value,
        ], true)) {
            throw new PayPayUnavailable('This order can no longer be paid.');
        }

        // R24: a fresh code must never outlive the order it collects for. The
        // status check above is not enough on its own — `payment_due_at` passes
        // the moment it passes, while `Expired` is only stamped when the reaper
        // next runs, and in that gap a mint would hand out a five-minute code on
        // an order `OrderPaymentService` has already decided is unpayable. The
        // customer would scan it, pay, and the money would land with nothing
        // willing to book it.
        if ($order->paymentDueAt() !== null && $order->paymentDueAt()->isPast()) {
            throw new PayPayUnavailable('The payment window for this order has closed.');
        }
    }

    /**
     * Hand back the code this order already has, when it is still the right one.
     *
     * A page reload is not a request for a new code, but it re-POSTs the mint —
     * so without this every refresh deleted the QR the guest may have been
     * holding open in the PayPay app, issued another, and restarted the
     * countdown at five minutes. Ten refreshes also exhausted the per-order mint
     * allowance and locked them out entirely.
     *
     * Three things must all hold, and each one failing means re-mint rather than
     * return something subtly wrong:
     *
     *  - an attempt is still live, so the code can still be credited;
     *  - the session is still cached, so we know the url WITHOUT asking PayPay
     *    whether a repeated merchant payment id is acceptable — a behaviour not
     *    worth betting money on;
     *  - the amount the code promises to collect still equals what is
     *    outstanding. A coupon, a split payment or an item added since the mint
     *    all break that, and handing back the stale code would collect the wrong
     *    sum.
     *  - the split intent behind that amount is unchanged. Amount alone is not
     *    enough on a dine-in bill: two ¥500 dishes make "I'll pay for the salad"
     *    and "I'll pay for the soup" arithmetically identical, and resuming
     *    across that switch would credit the dish the payer just deselected.
     *
     * The margin exists because a code within seconds of lapsing is worse than
     * no code: the guest scans it and PayPay refuses.
     *
     * @param  array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null  $split
     * @return array{qr_url: string, deeplink: string|null, merchant_payment_id: string, amount: float, expires_at: string|null, expires_in_seconds: int|null}|null
     */
    private function resumeOutstandingQr(OrderSnapshot $order, ?float $requestedAmount, ?array $split = null): ?array
    {
        $attempt = $this->liveAttempt($order);

        if ($attempt === null) {
            return null;
        }

        $merchantPaymentId = (string) $attempt->provider_object_id;
        $session = $this->recallSession($merchantPaymentId);

        if ($session === null || $session['expires_at'] === null) {
            return null;
        }

        $secondsLeft = (int) $session['expires_at'] - now()->getTimestamp();

        if ($secondsLeft < self::RESUME_MIN_SECONDS_LEFT) {
            return null;
        }

        // Read fresh rather than trusting the instance the request loaded. No
        // lock: a mismatch only ever sends us down the minting path, which takes
        // one — so the worst a race here can do is mint when it could have
        // resumed, never resume when it should have minted.
        $current = $this->reread($order);
        $outstanding = round($current->totalAmount() - $current->paidAmount(), 2);
        $wanted = $requestedAmount === null ? $outstanding : round($requestedAmount, 2);

        if ((float) $session['amount'] !== $wanted) {
            return null;
        }

        // A session minted before this field existed reports `none`, so an
        // in-flight code from a previous deploy re-mints once rather than being
        // resumed with attribution nobody recorded.
        if (($session['split_fingerprint'] ?? 'none') !== PayPayQrSplitIntent::fingerprint($split)) {
            return null;
        }

        return [
            'qr_url' => $session['qr_url'],
            'deeplink' => $session['deeplink'],
            'merchant_payment_id' => $merchantPaymentId,
            'amount' => (float) $session['amount'],
            'expires_at' => now()->setTimestamp((int) $session['expires_at'])->toIso8601String(),
            'expires_in_seconds' => $secondsLeft,
        ];
    }

    /**
     * #1737 — huỷ mã QR đang sống mà KHÔNG mint mã mới.
     *
     * Màn thanh toán tại bàn có nút "Đổi số tiền". Nó chỉ gỡ panel khỏi màn
     * hình: mã cũ **vẫn sống ở phía PayPay và vẫn quét trả được** tới khi hết
     * hạn (~5 phút), trong khi trình duyệt đã ngừng poll. Khách chuyển sang trả
     * quầy hoặc bỏ đi thì mã đó nằm lại, không ai theo dõi, và nếu bị quét thì
     * tiền vào sổ qua cron 15 phút — sau khi khách có thể đã trả bằng đường
     * khác. Đó là thu hai lần mà không ai làm gì sai.
     *
     * Mọi đường MINT đã an toàn sẵn: `createQrCode()` resume mã cũ khi số tiền
     * khớp, và gọi `invalidateOutstandingQr()` khi không. Thiếu đúng một đường
     * gọi nó mà không cần mint — nên đây KHÔNG viết logic huỷ thứ hai, chỉ mở
     * cửa vào đúng cái đã có.
     *
     * Trả `true` khi thật sự có mã bị huỷ. Không có mã ⇒ `false`, không phải
     * lỗi: người dùng bấm huỷ hai lần, hoặc mã vừa hết hạn, đều là chuyện bình
     * thường và chỗ gọi không có gì để làm khác đi.
     *
     * KHÔNG `assertPayable()`: đơn vừa đóng hoặc chi nhánh vừa tắt PayPay thì
     * mã cũ lại càng phải huỷ. Từ chối huỷ vì "không còn trả được" là giữ đúng
     * cái mã nguy hiểm ở đúng lúc nguy hiểm nhất.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * HỎI PAYPAY TRƯỚC, LUÔN LUÔN. Đây KHÔNG tái dùng `invalidateOutstandingQr()`,
     * và khác biệt đó là toàn bộ lý do hàm này tồn tại riêng.
     *
     * Bản đầu của #1737 gọi thẳng vào đó. `invalidateOutstandingQr()` xoá rồi
     * `abandon` mà không hỏi PayPay câu nào — chấp nhận được trên đường MINT,
     * vì ngay sau nó có mã mới thay thế nên một lần "đoán nhầm" vẫn còn đường
     * lùi. Đường huỷ không có gì thay thế cả, và `abandon` đánh attempt thành
     * `Canceled`, mà `Canceled` không nằm trong `LIVE_ATTEMPT_STATES`. Nghĩa là
     * sau đó attempt biến mất khỏi CẢ BA cửa ghi sổ:
     *
     *   - `liveAttempt()`            ⇒ poll của khách trả NOT_FOUND
     *   - `SweepStalePayPayQrAttempts::candidates()` ⇒ sweeper không nhìn lại nữa
     *   - `isTerminalAttemptState()` ⇒ webhook thành `paypay_ignored_terminal`
     *
     * Khách quét xong, đang bấm xác nhận trong app PayPay, mà lúc đó huỷ chạy ⇒
     * tiền ở PayPay, không sổ sách, không cảnh báo, không ai biết mà chữa. Lỗi
     * cũ (thu thừa) ít nhất còn hú còi `paypay_payment_would_overpay`; cái này
     * thì im lặng. Đổi một lỗi ồn ào lấy một lỗi im lặng là đi lùi.
     *
     * Luật đã ghi ở `OrderPaymentOrchestrationCompat::retirePayPayQrAttempt()`:
     * *"Callers MUST have asked the provider first. Nothing local proves a code
     * went unscanned, and retiring an attempt whose money already moved makes
     * that money unbookable."* `SweepStalePayPayQrAttempts` tuân thủ đúng luật
     * đó — `retireIfLapsed()` bail ngay khi `paypay_payment_id !== null`. Hàm
     * này bail ở đúng chỗ ấy, vì cùng một lý do.
     *
     * @param  string|null  $merchantPaymentId  Mã mà chỗ gọi ĐANG NHÌN. Lệch với
     *                                          mã sống hiện tại ⇒ no-op. `liveAttempt()` luôn lấy attempt mới nhất, nên
     *                                          thiếu tham số này thì một lượt huỷ đến muộn sẽ giết mã mà khách vừa mint
     *                                          xong và đang nhìn. Cùng bài học với `statusPayload` (*"names WHICH code
     *                                          this answer is about"*) và `isPayPayScreenSuperseded` ở FE.
     */
    public function cancelOutstandingQr(OrderSnapshot $order, ?string $merchantPaymentId = null): bool
    {
        $attempt = $this->liveAttempt($order);

        if ($attempt === null) {
            return false;
        }

        $liveMerchantPaymentId = (string) $attempt->provider_object_id;

        if ($merchantPaymentId !== null && $merchantPaymentId !== $liveMerchantPaymentId) {
            return false;
        }

        $connection = PaymentGatewayConnection::query()->findOrFail($attempt->connection_id);
        $connectionData = GatewayConnectionDataFactory::fromModel($connection);
        $correlationId = 'paypay:qr:cancel:'.$liveMerchantPaymentId;

        // Chỉ 404 thành null. 5xx / timeout NÉM — và ném là đúng: không kết luận
        // "chưa ai quét" từ một lần không hỏi được. Controller để nó thành lỗi và
        // KHÔNG ghi gì cục bộ, sweeper dọn sau.
        $details = $this->qrCodes->findPayment($connectionData, $liveMerchantPaymentId, $correlationId);

        // PayPay không còn biết mã này (đã xoá / đã hết hạn). Không có gì để xoá
        // nữa, nhưng attempt vẫn treo — đóng nó lại cho khớp sự thật.
        if ($details === null) {
            $this->retireCancelledQr($order, $attempt, $liveMerchantPaymentId, 'qr_cancel_gone_at_provider');

            return true;
        }

        // TIỀN ĐÃ CHUYỂN. Ghi sổ, tuyệt đối không abandon. Đi qua đúng cái funnel
        // `syncStatus()` dùng, để hai đường không bao giờ ghi hai số khác nhau.
        if ($this->mapper->mapQrPaymentState($details['status']) === PaymentAttemptStateEnum::Succeeded) {
            $currency = strtoupper((string) ($details['currency'] ?? 'JPY'));

            $this->orderPayments->recordPayPayPaymentByOrderId(
                $order->aggregateId(),
                $liveMerchantPaymentId,
                $this->toMajorUnits((int) ($details['amount'] ?? 0), $currency),
                $currency,
                (string) $attempt->id,
            );

            // `MoneyOrchestrationLog` chỉ phát ở mức error — đó là hợp đồng
            // cảnh báo của nó. Đây không phải lỗi: hỏi trước rồi phát hiện tiền
            // đã chuyển là đúng cái luồng này sinh ra để làm.
            Log::channel('payment_orchestration')->info('[payments.paypay] paypay_qr_cancel_found_paid', [
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $liveMerchantPaymentId,
            ]);

            // Không có mã nào bị huỷ — có một khoản tiền được ghi sổ.
            return false;
        }

        // Một cú quét đang bay. Để yên. Nguyên văn bài học của sweeper:
        // "CREATED, WITH a payment id — a scan is in flight. Leave it alone."
        if (($details['paypay_payment_id'] ?? null) !== null) {
            Log::channel('payment_orchestration')->info('[payments.paypay] paypay_qr_cancel_refused_scan_in_flight', [
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $liveMerchantPaymentId,
                'paypay_payment_id' => $details['paypay_payment_id'],
            ]);

            return false;
        }

        // Tới đây mới chắc: mã còn sống và chưa ai quét.
        //
        // `delete()` KHÔNG ném — nó `catch (\Throwable) { return false; }`. Nên
        // giá trị trả về là tín hiệu DUY NHẤT, và bỏ qua nó (như
        // `invalidateOutstandingQr` đang làm) là đánh attempt thành terminal
        // trong khi mã vẫn quét được ở PayPay: vừa mất đường ghi sổ, vừa còn
        // nguyên mã sống. Xoá được thì mới đóng.
        // Theo `codeId` của PayPay. Không nhớ được (cache mất) ⇒ coi như không
        // xoá được: thà để mã sống và sweeper dọn, còn hơn đóng attempt trong
        // khi mã vẫn quét được.
        $codeId = $this->recallCodeId($liveMerchantPaymentId);

        if ($codeId === null || ! $this->qrCodes->delete($connectionData, $codeId, $correlationId)) {
            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_PAYPAY, 'paypay_qr_cancel_delete_failed', [
                'order_id' => $order->aggregateId(),
                'merchant_payment_id' => $liveMerchantPaymentId,
            ]);

            return false;
        }

        $this->retireCancelledQr($order, $attempt, $liveMerchantPaymentId, 'qr_cancelled_by_customer');

        return true;
    }

    /**
     * Đóng attempt của một mã vừa bị huỷ, với LÝ DO THẬT.
     *
     * `abandonPayPayQrAttempt()` ghi raw status `qr_create_failed` — đúng cho
     * đường nó sinh ra (mint hỏng giữa chừng), sai hoàn toàn ở đây: việc tạo mã
     * không hề hỏng, khách bấm nút. Docblock của `retirePayPayQrAttempt` nói rõ
     * hệ thống phải phân biệt được *"whether the code died in our hands or in
     * the customer's"*, nên dùng nó và nói thẳng ra.
     */
    private function retireCancelledQr(
        OrderSnapshot $order,
        PaymentAttempt $attempt,
        string $merchantPaymentId,
        string $rawStatus,
    ): void {
        $this->orchestration->retirePayPayQrAttempt(
            $order,
            (string) $attempt->id,
            $merchantPaymentId,
            $rawStatus,
            (int) $attempt->version,
        );

        // Quyền quy kết theo mã đã chết cùng nó — để lại thì một claim by-items
        // cũ còn với tới được suốt một ngày sau khi người trả đổi ý.
        PayPayQrSplitIntent::forget($merchantPaymentId);
    }

    /**
     * Kill any code still outstanding for this order before minting another.
     *
     * Two scannable codes for one order is how an overpayment happens without
     * anybody doing anything wrong.
     */
    private function invalidateOutstandingQr(OrderSnapshot $order, mixed $connectionData): void
    {
        $attempt = $this->liveAttempt($order);

        if ($attempt === null) {
            return;
        }

        $merchantPaymentId = (string) $attempt->provider_object_id;

        // #1737 — theo `codeId`, không theo `merchantPaymentId`. Trước đây chỗ
        // này đưa nhầm id nên mã cũ vẫn sống ở PayPay sau mỗi lần mint lại.
        $codeId = $this->recallCodeId($merchantPaymentId);

        if ($codeId !== null) {
            $this->qrCodes->delete($connectionData, $codeId, 'paypay:qr:replace:'.$merchantPaymentId);
        }
        $this->orchestration->abandonPayPayQrAttempt($order, (string) $attempt->id, $merchantPaymentId);

        // The retired code's attribution goes with it. Leaving it behind is
        // harmless while the mpid stays unique, but it would keep a stale
        // by-items claim reachable for a day after the payer changed their mind.
        PayPayQrSplitIntent::forget($merchantPaymentId);
    }

    private function liveAttempt(OrderSnapshot $order): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('customer_order_id', $order->aggregateId())
            ->whereNotNull('provider_object_id')
            // Shared with the stale-attempt sweeper. Two independent answers to
            // "is this attempt still open" is how one of them ends up asking
            // PayPay about a code the other has already retired.
            ->whereIn('state', PayPayQrCodeClient::LIVE_ATTEMPT_STATES)
            ->orderByDesc('created_at')
            ->get()
            // Filtered in PHP rather than SQL: the prefix is what distinguishes a
            // QR attempt from any other provider reference on the same order.
            ->first(fn (PaymentAttempt $attempt): bool => PayPayQrCodeClient::isQrMerchantPaymentId((string) $attempt->provider_object_id));
    }

    /**
     * `expires_in_seconds` is echoed so the client never has to decide on its own
     * that a code lapsed. Without it the countdown is the only signal the browser
     * has, and a wallet settling a second after that hits zero would be read as
     * expired while the money is already moving.
     *
     * `merchant_payment_id` names WHICH code this answer is about. The poll takes
     * only an order id and always resolves the newest attempt, so without it a
     * screen holding an older code reads the new code's state as its own — and
     * re-anchors its countdown to the new code's remaining life, showing a
     * healthy ticking timer on a QR that was deleted at PayPay. Two tabs, two
     * phones on a split bill, or a StrictMode double-mint all reach that. The
     * client compares this against the code it is displaying.
     *
     * @return array{status: string, is_fully_paid: bool, order_status: string, expires_in_seconds: int|null, merchant_payment_id: string|null}
     */
    private function statusPayload(
        OrderSnapshot $order,
        string $status,
        ?int $expiresInSeconds = null,
        ?string $merchantPaymentId = null,
    ): array {
        return [
            'status' => $status,
            'is_fully_paid' => $order->paidAmount() >= $order->totalAmount(),
            'order_status' => $order->status(),
            'expires_in_seconds' => $expiresInSeconds,
            'merchant_payment_id' => $merchantPaymentId,
        ];
    }
}
