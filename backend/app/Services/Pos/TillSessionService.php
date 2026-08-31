<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CouponRedemption;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Till;
use App\Models\TillCashDenominationCount;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\ForceAbandonReasonCodeEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TillCashEventTypeEnum;
use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Omnify\Enums\TillSettlementKindEnum;
use App\Omnify\Enums\TillTenderSystemCategoryEnum;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Order\Contracts\BranchOrderReads;
use App\Services\Order\Contracts\BranchOrderSettingsLock;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use App\Support\BusinessClock;
use App\Support\ZeroDecimalCurrency;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * TillSessionService — Plan 030 (Cashier Shift).
 *
 * Owns the full open → close / abandon state machine of a cashier shift. All
 * mutating methods run inside DB::transaction with lockForUpdate() on the
 * Till row to enforce "at most one open session per till" (BR-TS02). All
 * reads use TillSession scopes from App\Models\TillSession (Phase 2).
 *
 * Method map:
 *   - open()             Mở ca: float đầu ca theo mệnh giá + stamp till.current_session
 *   - recordCashEvent()  入金/出金 giữa ca; tác động trực tiếp expected_cash
 *   - saveDraft()        Lưu nháp khi kết ca (open → closing)
 *   - close()            Chốt ca: closing counts + settlement details + variances
 *   - abandon()          Huỷ ca mở nhầm (chỉ khi chưa có payment stamped)
 *   - forceAbandon()     [Plan-032] Manager dứt khoát huỷ ca treo. Bypass
 *                        SHIFT_HAS_PAYMENTS guard; stamps force_abandoned_by_id
 *                        + reason_code/detail for audit (BR-TS06 manager path).
 *   - expire()           [Plan-032] System-driven flip open/closing → expired
 *                        khi opened_at quá threshold AND no recent activity.
 *                        Re-checks activity window INSIDE locked transaction
 *                        to close the race window between outer SELECT and
 *                        inner UPDATE (DESIGN Decision 10).
 *   - manualSettle()     [Plan-032] Manager reconcile an expired session
 *                        post-hoc. Accepts opening_counts_override +
 *                        post_hoc_cash_events for cases where the cashier
 *                        disappeared mid-shift (DESIGN Decision 8b).
 *   - reconcile()        Read-only compute revenue + expected cash + per-tender
 *   - resolveTillForBranch()  Helper used by ResolveOpenTillSession middleware.
 */
class TillSessionService
{
    public function __construct(
        private readonly OrderPaymentLedgerWriter $orderPaymentLedger,
        // #1647 — cổng ĐỌC ĐƠN của Ordering. Có mặt để các chỗ dưới đây thôi tự
        // truy vấn `customer_orders` (kể cả #2696 đơn treo tiền); phần lọc theo
        // payment vẫn ở lại đây, trên bảng của chính Payments.
        private readonly BranchOrderReads $orders,
        // #962 — cổng KHOÁ-RỒI-ĐỌC `shop_order_settings`. Cái khoá là AUDIT FIX
        // 3.6, không phải chi tiết cài đặt: xem `BranchOrderSettingsLock`.
        private readonly BranchOrderSettingsLock $branchOrderSettings,
        // #962 — phần chia theo thuế suất của một tập đơn (plan-046 R4 / インボイス).
    ) {}

    public const DEFAULT_TILL_CODE = 'MAIN';

    /** Số đơn hiện trong `{{order_codes}}` trước khi rơi về hậu tố `+N` (#2739). */
    private const UNRESOLVED_ORDER_CODES_SHOWN = 5;

    /**
     * Plan-044 — the "virtual queue" (R2): orders in a non-terminal lifecycle
     * state are candidates for carry-over re-stamp at shift open. Terminal
     * states (`closed`, `voided`, `expired`) are frozen and never re-stamped
     * (R3). Kept as a public constant so the S4 orphan-safety-net command and
     * regression tests share the exact same predicate.
     *
     * @var list<string>
     */

    // =========================================================================
    //  Query
    // =========================================================================

    public function findById(string $id): TillSession
    {
        return TillSession::with([
            'till',
            'openingCounts.denomination',
            'closingCounts',
            'cashEvents',
            'settlementDetails.tenderType',
        ])->findOrFail($id);
    }

    /**
     * Return the branch's Till and its open session (or null).
     *
     * @return array{till: Till, open_session: ?TillSession}
     */
    public function currentForBranch(string $branchId): array
    {
        $till = $this->resolveTillForBranch($branchId);
        $openSession = null;
        if ($till->current_session_id !== null) {
            $openSession = TillSession::with([
                'openingCounts.denomination',
                'cashEvents',
            ])
                ->where('id', $till->current_session_id)
                ->inProgress()
                ->first();
        }

        return ['till' => $till, 'open_session' => $openSession];
    }

    /**
     * Plan-044 — resolve the branch's currently-**open** cashier shift id, or
     * null. This is the order-attribution resolver (R1): an order belongs to
     * the shift that *serves* it, and a shift only serves while it is `open`.
     *
     * Deliberately **open-only** (scopeOpen), NOT `inProgress`: once a cashier
     * starts kết-ca the shift is `closing` and must not adopt new orders — those
     * belong to the gap and are carried to the next shift at open() time. This
     * differs from payment attribution (plan-030), which keeps `inProgress`
     * (open OR closing) semantics because a drawer can still receive money while
     * closing.
     *
     * Read-only: resolves via the Till's authoritative `current_session_id`
     * pointer, so no Till row is created on the (possibly anonymous) order path.
     */
    public function openSessionIdForBranch(string $branchId): ?string
    {
        $currentSessionId = Till::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('current_session_id')
            ->value('current_session_id');

        if ($currentSessionId === null) {
            return null;
        }

        return TillSession::query()
            ->whereKey($currentSessionId)
            ->open()
            ->value('id');
    }

    /**
     * Plan-044 — resolve the branch's currently **in-progress** cashier shift id
     * (open OR closing), or null. This is the *payment*-attribution resolver:
     * a drawer keeps receiving money while `closing` (plan-030 semantics), so
     * payments — unlike orders — bind to `inProgress`, not `open`-only.
     *
     * Read-only: resolves via the Till's authoritative `current_session_id`
     * pointer, so no Till row is created on the (device) payment sync path.
     */
    public function inProgressSessionIdForBranch(string $branchId): ?string
    {
        $currentSessionId = Till::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('current_session_id')
            ->value('current_session_id');

        if ($currentSessionId === null) {
            return null;
        }

        return TillSession::query()
            ->whereKey($currentSessionId)
            ->inProgress()
            ->value('id');
    }

    /**
     * #2657 — is THIS shift still holding the drawer? Answers the question for
     * one specific session id (the one stamped on a payment), where the two
     * resolvers above answer it for a branch's *current* session.
     *
     * `inProgress` (open OR closing), not `open`-only, on purpose: it must match
     * the set that money attribution already uses (plan-030). A drawer keeps
     * taking and returning cash while `closing` — that is exactly when the
     * cashier is counting it — so a refund landing elsewhere during `closing` is
     * the worst case, not an exempt one.
     *
     * A null id means the payment was never attributed to a drawer, so there is
     * no shift to still be open: false.
     */
    public function sessionIsInProgress(?string $sessionId): bool
    {
        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        return TillSession::query()
            ->whereKey($sessionId)
            ->inProgress()
            ->exists();
    }

    /**
     * Plan-044 R6 — Cloud-authoritative accept rule for a `till_session_id`
     * supplied on a workstation sync-UP payload.
     *
     * The workstation remaps its local session id to the Cloud id before
     * sending, but Cloud stays authoritative: a provided id is honoured ONLY if
     * it resolves to a session **of this branch** that is still in the required
     * lifecycle state (`$openOnly` → `open`; otherwise `inProgress`, i.e. the
     * payment set). Anything foreign, terminal, or unknown is silently dropped
     * to the branch's current session in that state — never a 422/dead-letter
     * (Decision 5: attribution must never block a money-bearing sync item).
     * Returns null when the branch has no session in the required state.
     */
    public function resolveSyncedSessionId(?string $providedId, string $branchId, bool $openOnly): ?string
    {
        if ($providedId !== null) {
            $query = TillSession::query()
                ->whereKey($providedId)
                ->where('branch_id', $branchId);

            $query = $openOnly ? $query->open() : $query->inProgress();

            if ($query->exists()) {
                return $providedId;
            }
        }

        return $openOnly
            ? $this->openSessionIdForBranch($branchId)
            : $this->inProgressSessionIdForBranch($branchId);
    }

    /**
     * #1129 — every branch of a brand that is mid-shift right now.
     *
     * plan-043 Q6 deliberately does NOT block a tax-rate edit: per-line
     * snapshots protect orders already created. But an HQ rate edit lands on
     * EVERY branch at once, so a shift can end up spanning two rates with the
     * operator told nothing. This returns who is affected so the caller can
     * warn with real names instead of a static hint.
     *
     * Uses the SAME predicate the 409 guards use — an open/closing shift, or a
     * chain awaiting continuation after a handover — so a warning can never
     * disagree with a block elsewhere.
     *
     * @return array<int, array{id: string, name: ?string}>
     */
    public function openShiftBranchesForBrand(string $brandId): array
    {
        // Branch links to Brand through console_brand_id, NOT the brand's own
        // primary key (see Brand::branches()), so resolve the brand row first.
        $brand = Brand::query()->whereKey($brandId)->first(['console_brand_id']);
        if ($brand === null) {
            return [];
        }

        $branchIds = Branch::query()
            ->where('console_brand_id', $brand->console_brand_id)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        if ($branchIds === []) {
            return [];
        }

        $withOpenShift = Till::query()
            ->join('till_sessions', 'tills.current_session_id', '=', 'till_sessions.id')
            ->whereIn('tills.branch_id', $branchIds)
            ->whereIn('till_sessions.status', [
                TillSessionStatusEnum::Open->value,
                TillSessionStatusEnum::Closing->value,
            ])
            ->pluck('tills.branch_id')
            ->map(strval(...))
            ->unique()
            ->all();

        $affected = collect($branchIds)
            ->filter(fn (string $id): bool => in_array($id, $withOpenShift, true) || $this->branchHasOpenChain($id))
            ->values();

        if ($affected->isEmpty()) {
            return [];
        }

        return Branch::query()
            ->whereIn('id', $affected->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch): array => [
                'id' => (string) $branch->id,
                'name' => $branch->name === null ? null : (string) $branch->name,
            ])
            ->all();
    }

    /**
     * Có till nào ở chi nhánh đang giữ một CHUỖI mở không (#1690).
     *
     * Trước: `Till::get()` rồi gọi `previousTerminalSessionForTill()` cho TỪNG
     * till — N+1, và mỗi lần gọi lại `->get()` toàn bộ lịch sử phiên terminal
     * của till đó. Guard đổi cấu hình giữa ca gọi vị từ này BÊN TRONG một
     * transaction đang giữ `lockForUpdate` trên `shop_order_settings`, đúng hàng
     * mà `open()` tranh chấp, nên mỗi truy vấn thừa ở đây là thời gian một thu
     * ngân bị chặn mở ca.
     *
     * Giờ là HAI truy vấn: id các till, rồi một lượt lấy phiên terminal của cả
     * cụm. Việc chọn "phiên mới nhất" vẫn đi qua {@see latestTerminalOf()} —
     * cùng một comparator, nên tie-break plan-046 P6-4 không đổi một bit.
     *
     * CÒN NỢ: vẫn nạp toàn bộ lịch sử terminal của chi nhánh. Chặn được nó cần
     * đưa `max(closed_at, expired_at, abandoned_at)` xuống SQL, mà `GREATEST`
     * là MySQL-only (SQLite trong test không có — chính cái bẫy đã làm
     * `MenuPromotionService::report()` hỏng chỉ trên production). Viết biểu thức
     * `CASE WHEN` di động cho ba cột nullable là một thay đổi riêng, có test
     * riêng. Xem #1690.
     */
    public function branchHasOpenChain(string $branchId): bool
    {
        $tillIds = Till::query()->where('branch_id', $branchId)->pluck('id');

        if ($tillIds->isEmpty()) {
            return false;
        }

        return $this->terminalSessionsFor($tillIds->all())
            ->groupBy('till_id')
            ->contains(function ($sessions): bool {
                $latest = $this->latestTerminalOf($sessions);

                return $latest !== null
                    && $latest['session']->settlement_kind === TillSettlementKindEnum::Handover;
            });
    }

    /**
     * Mốc kết thúc của một phiên terminal, tính trong SQL (#1690).
     *
     * `MAX(closed_at, expired_at, abandoned_at)` — và phải là MAX chứ không phải
     * `COALESCE`: plan-032 cho phép quản lý đối soát tay một phiên đã HẾT HẠN,
     * nên `expired_at` và `closed_at` cùng có giá trị, và first-non-null sẽ trả
     * về mốc SỚM hơn.
     *
     * Viết bằng `CASE WHEN` chứ KHÔNG dùng `GREATEST`: `GREATEST` là MySQL-only,
     * SQLite (thứ `phpunit.xml` dùng) không có nó. Đó đúng là cái bẫy đã làm
     * `MenuPromotionService::report()` hỏng CHỈ trên production — nhánh dùng nó
     * chưa bao giờ chạy trong suite. CLAUDE.md ghi lại vụ đó.
     *
     * Mốc mồi `1970-01-01` chỉ đóng vai "nhỏ hơn mọi mốc thật" trong phép so
     * sánh; hàng nào cả ba cột đều NULL đã bị lọc ra trước đó nên nó không bao
     * giờ là giá trị thắng. Cả hai engine so sánh datetime dạng ISO theo thứ tự
     * từ điển nên biểu thức này di động.
     */
    private const TERMINAL_END_EXPR = <<<'SQL'
        CASE
            WHEN COALESCE(closed_at, '1970-01-01 00:00:00') >= COALESCE(expired_at, '1970-01-01 00:00:00')
             AND COALESCE(closed_at, '1970-01-01 00:00:00') >= COALESCE(abandoned_at, '1970-01-01 00:00:00')
                THEN COALESCE(closed_at, '1970-01-01 00:00:00')
            WHEN COALESCE(expired_at, '1970-01-01 00:00:00') >= COALESCE(abandoned_at, '1970-01-01 00:00:00')
                THEN COALESCE(expired_at, '1970-01-01 00:00:00')
            ELSE COALESCE(abandoned_at, '1970-01-01 00:00:00')
        END
        SQL;

    /**
     * Chỉ những phiên terminal có thể là MỚI NHẤT của till mình — thường đúng
     * một hàng mỗi till (#1690).
     *
     * Trước đó hàm này nạp TOÀN BỘ lịch sử phiên đã đóng để rồi vứt gần hết:
     * một till chạy một năm là ~365 hàng hydrate thành model, và `open()` gọi
     * nó khi đang giữ `lockForUpdate`.
     *
     * Hai truy vấn: mốc kết thúc lớn nhất của từng till, rồi các hàng đạt mốc
     * đó. Số hàng trả về bằng số till, trừ khi có hoà — và hoà chính là ca mà
     * tie-break plan-046 P6-4 tồn tại để xử, nên chúng PHẢI được giữ lại và
     * đưa qua comparator, không được cắt bằng `LIMIT 1` trong SQL.
     *
     * @param  list<string>  $tillIds
     * @return Collection<int, TillSession>
     */
    private function terminalSessionsFor(array $tillIds)
    {
        if ($tillIds === []) {
            return TillSession::query()->whereRaw('1 = 0')->get();
        }

        $terminal = fn ($query) => $query
            ->whereIn('till_id', $tillIds)
            ->whereIn('status', [
                TillSessionStatusEnum::Settled->value,
                TillSessionStatusEnum::Abandoned->value,
                TillSessionStatusEnum::Expired->value,
            ])
            // Tương đương `filter($r['end'] !== null)` của bản cũ: một phiên
            // terminal không mang mốc nào thì không xếp thứ tự được.
            ->where(fn ($q) => $q->whereNotNull('closed_at')
                ->orWhereNotNull('expired_at')
                ->orWhereNotNull('abandoned_at'));

        $maxPerTill = $terminal(TillSession::query())
            ->selectRaw('till_id, MAX('.self::TERMINAL_END_EXPR.') as max_end')
            ->groupBy('till_id')
            ->get();

        if ($maxPerTill->isEmpty()) {
            return TillSession::query()->whereRaw('1 = 0')->get();
        }

        return $terminal(TillSession::query())
            ->where(function ($q) use ($maxPerTill) {
                foreach ($maxPerTill as $row) {
                    $q->orWhere(fn ($inner) => $inner
                        ->where('till_id', $row->till_id)
                        // `>=` chứ không phải `=`: mốc lớn nhất đã do chính biểu
                        // thức này tính ra, nên `>=` chọn đúng tập ấy mà không
                        // phụ thuộc cách từng driver trả kiểu về PHP.
                        ->whereRaw(self::TERMINAL_END_EXPR.' >= ?', [$row->max_end]));
                }
            })
            ->get();
    }

    /**
     * Phiên terminal mới nhất trong một tập, kèm mốc kết thúc của nó.
     *
     * Đây là chỗ DUY NHẤT quyết định "mới nhất", nên cả `branchHasOpenChain`
     * lẫn `previousTerminalSessionForTill` cùng ăn một luật.
     *
     * Plan-046 P6-4 — tie-break tất định. Riêng `sortByDesc('end')` là KHÔNG
     * tất định khi hai phiên terminal cùng till trùng mốc kết thúc (một
     * handover-settle và một abandon trong cùng một tick) — mà CẢ R1 (nối chuỗi)
     * lẫn R8 (guard tiền tệ) đều ăn theo bộ giải này, nên một lần chọn bấp bênh
     * sẽ lật giữa "nối chuỗi" và "chuỗi mới". Thứ tự: end DESC, rồi
     * chain_sequence DESC, rồi id DESC (ổn định).
     *
     * Một phiên có thể mang NHIỀU mốc: plan-032 cho phép quản lý đối soát tay
     * một phiên đã hết hạn, nên `expired_at` và `closed_at` cùng có giá trị —
     * đó là lý do phải lấy MAX chứ không phải `COALESCE`.
     *
     * @param  iterable<TillSession>  $sessions
     * @return array{session: TillSession, end: CarbonInterface}|null
     */
    private function latestTerminalOf(iterable $sessions): ?array
    {
        return collect($sessions)
            ->map(fn (TillSession $s) => [
                'session' => $s,
                'end' => collect([$s->closed_at, $s->expired_at, $s->abandoned_at])
                    ->filter()->map(fn ($ts) => Carbon::parse($ts))->max(),
            ])
            ->filter(fn ($r) => $r['end'] !== null)
            ->sort(function (array $a, array $b): int {
                if (! $a['end']->eq($b['end'])) {
                    return $a['end']->lt($b['end']) ? 1 : -1;
                }
                $seq = ((int) $b['session']->chain_sequence) <=> ((int) $a['session']->chain_sequence);

                return $seq !== 0 ? $seq : strcmp((string) $b['session']->id, (string) $a['session']->id);
            })
            ->first();
    }

    /**
     * Plan-044 R2 — the most recent TERMINAL session on a branch's till and its
     * end instant. Terminal = settled / abandoned / expired; the end is the
     * latest of closed_at / expired_at / abandoned_at (a session can end any of
     * the three ways — plan-030/032). Returns [session, end] or null when the
     * till has no prior terminal session (first shift ever) — callers must then
     * skip the gap sweep entirely rather than sweep unbounded history.
     *
     * @return array{session: TillSession, end: CarbonInterface}|null
     */
    public function previousTerminalSessionForTill(Till $till): ?array
    {
        // Cùng bộ giải với `branchHasOpenChain` (#1690) — hai bản sao của luật
        // tie-break là hai chỗ để R1 và R8 lệch nhau.
        return $this->latestTerminalOf($this->terminalSessionsFor([(string) $till->id]));
    }

    /**
     * Plan-054 D10 — money no drawer ever held is not a gap payment.
     *
     * A customer-web checkout (Stripe today, PayPay next) keeps
     * `till_session_id = NULL` forever BY DESIGN — no cashier collected it — so
     * it looks exactly like a gap payment to an attribution-only filter. Let it
     * onto the claim panel and a cashier eventually claims it: the amount then
     * lands in a tender bucket at close as a variance nobody can account for,
     * and close() aborts 422 VARIANCE_REASON_REQUIRED on the qr/emoney buckets
     * — the shift becomes unclosable. Applied to BOTH the preview and the claim,
     * because a stale pos-web build (or a plain curl) posts ids the preview never
     * showed it, and the wall has to stand where the money is attributed.
     *
     * NULL channel stays IN: it is the pre-#1059 shape and what drawer-side
     * callers still write, i.e. precisely the rows this panel exists to
     * reconcile — and a bare `channel != 'customer_web'` would silently drop
     * every one of them (SQL three-valued logic).
     *
     * @return \Closure(Builder): void
     */
    private function collectedAtTheDrawer(): \Closure
    {
        return function ($q) {
            $q->whereNull('order_payments.channel')
                ->orWhere('order_payments.channel', '!=', PaymentChannelEnum::CustomerWeb->value);
        };
    }

    /**
     * Trạng thái của một khoản thu **có thể còn tiền trong két** (#2744).
     *
     * `refunded` KHÔNG có nghĩa "đã hoàn hết". Sổ hoàn tiền của repo này giữ
     * hàng gốc nguyên `+X` và chỉ đổi status sang `refunded`, còn khoản hoàn là
     * một hàng RIÊNG mang `refund_of_id` và số ÂM — kể cả khi chỉ hoàn một phần.
     * Lọc `status = succeeded` vì thế loại mất một khoản 5000 đã hoàn 1000, dù
     * két vẫn đang giữ 4000 thật.
     *
     * Cùng từ vựng với {@see OrderPayment::netCollectedForOrder()} — đó là vị
     * ngữ tiền chuẩn của repo, và đây là bản dùng cho MỘT khoản thay vì cả đơn.
     *
     * @return list<string>
     */
    private static function statusesThatMayStillHoldMoney(): array
    {
        return [
            PaymentStatusEnum::Succeeded->value,
            PaymentStatusEnum::Refunded->value,
        ];
    }

    /**
     * NET của từng khoản còn dương — hàng gốc cộng mọi hàng hoàn trỏ về nó.
     *
     * Dùng ở CẢ HAI đầu của cặp đọc/ghi gap payment ({@see gapPreview()} và
     * {@see claimGapPayments()}). Đó là điểm chính, không phải tiện tay gom
     * code: hai đầu lệch nhau nghĩa là preview hiện một khoản, thu ngân tích,
     * rồi claim từ chối đóng dấu — tiền được xác nhận trên màn hình mà không
     * vào ca nào. Sửa một đầu là tạo ra đúng lỗi đó (#2736 đã trả giá một lần
     * cho cùng hình dạng ở tầng workstation).
     *
     * `> 0` chứ không `>= 0`: hoàn hết thì két không giữ gì, không có gì để gán.
     */
    private function netStillInTheDrawer(): \Closure
    {
        return function ($q) {
            $q->whereRaw('('.self::UNATTRIBUTED_NET_SQL.') > 0', self::statusesThatMayStillHoldMoney());
        };
    }

    /**
     * NET của một khoản, chỉ trừ những hàng hoàn CHƯA gắn ca (#2744 vòng 2).
     *
     * `till_session_id IS NULL` trên hàng hoàn là điều kiện load-bearing, không
     * phải cho gọn: một khoản hoàn xảy ra SAU khi ca kế đã mở thì `reconcile()`
     * (#523) đã trừ −Y vào ngăn kéo của CHÍNH ca đó. Trừ nó lần nữa ở đây là
     * đếm hai lần, và preview sẽ báo 700 trong khi tiền giữ riêng thật là 1.000.
     *
     * Chỉ khoản hoàn còn NULL mới thật sự đã rời ngăn kéo mà chưa ca nào gánh.
     */
    private const UNATTRIBUTED_NET_SQL = 'order_payments.amount + COALESCE((
                    SELECT SUM(r.amount) FROM order_payments r
                     WHERE r.refund_of_id = order_payments.id
                       AND r.till_session_id IS NULL
                       AND r.status IN (?, ?)
                ), 0)';

    /**
     * Plan-044 R2 — gap-payment preview for the shift-open screen.
     *
     * Lists the branch's `till_session_id IS NULL` payments taken during the gap
     * window `(prev_end, now]` — i.e. after the previous shift ended and before
     * this one opens — so the cashier can reconcile them (against the physically
     * held cash + a paper note) and confirm which to attribute to the new shift.
     * Each row is tagged `is_cash` using the SAME classifier the drawer reconcile
     * uses (`payment_methods.code === 'cash'`), because that's exactly the set a
     * naive re-stamp would double-count against the opening float (DESIGN R2 §3).
     * No prior terminal session → empty (window cannot be bounded).
     *
     * #2724 — két nhận `till_code` từ caller. Bỏ trống thì vẫn là MAIN, và đó là
     * CỐ Ý: đây là màn ĐỌC của một cặp đọc/ghi. Đường GHI là
     * {@see claimGapPayments()}, chạy trong `open()` trên đúng két mà `open()`
     * khoá — tức `till_code` của lượt mở, mặc định MAIN. Nếu đường đọc tự ý giải
     * ra một két KHÁC (ví dụ "két có ca kết thúc gần nhất") thì preview hiện một
     * khoản, thu ngân tích, rồi lượt claim đo cửa sổ của két khác và TỪ CHỐI
     * đóng dấu: tiền được xác nhận trên màn hình mà không vào ca nào. Muốn quét
     * đúng một két khác thì client phải truyền `till_code` cho CẢ hai đầu.
     *
     * (`unresolvedOrdersPreview()` được phép tự giải vì nó không có đường ghi
     * nào đi kèm — nó chỉ nhìn ĐƠN, không gán tiền vào ca.)
     *
     * @return array{previous_session: ?array, gap_window: ?array, currency_code: string, payments: array<int, array<string, mixed>>, totals: array{count: int, cash_amount: float, non_cash_amount: float}}
     */
    public function gapPreview(string $branchId, ?string $tillCode = null): array
    {
        // #2745 — đường ĐỌC không được tạo két. `?till_code=` tới thẳng từ
        // caller, nên `firstOrCreate` ở đây biến một GET thành lệnh dựng hàng
        // `tills` với mã bịa.
        $till = $this->existingTillForBranch(
            $branchId,
            $tillCode !== null && $tillCode !== '' ? $tillCode : self::DEFAULT_TILL_CODE
        );
        $currency = app(BranchCurrency::class)->codeFor($branchId)
            ?: ($till->default_currency_code ?? 'JPY');

        $empty = [
            'previous_session' => null,
            'gap_window' => null,
            'currency_code' => $currency,
            'payments' => [],
            'totals' => ['count' => 0, 'cash_amount' => 0.0, 'non_cash_amount' => 0.0],
        ];

        // Két không tồn tại ⇒ rỗng, đúng ngữ nghĩa sẵn có: cặp đọc/ghi này CỐ Ý
        // không tự giải két, và "chưa có ca nào trước đó" cũng trả về đúng hình
        // dạng này. Không cần 422 riêng — hợp đồng thành công giữ nguyên.
        if ($till === null) {
            return $empty;
        }

        $prev = $this->previousTerminalSessionForTill($till);
        if ($prev === null) {
            return $empty;
        }

        /** @var TillSession $prevSession */
        $prevSession = $prev['session'];
        $prevEnd = $prev['end'];
        $now = now();

        // Sale originals with NULL attribution in the window. Refund ROWS stay
        // out of the list (they are the −Y side, not money taken), but a
        // partially-refunded original stays IN: it flipped to `refunded` while
        // the drawer still holds the net (#2744).
        $rows = OrderPayment::query()
            ->where('order_payments.branch_id', $branchId)
            ->whereNull('order_payments.till_session_id')
            ->where($this->collectedAtTheDrawer())
            ->whereNull('order_payments.refund_of_id')
            ->whereIn('order_payments.status', self::statusesThatMayStillHoldMoney())
            ->where($this->netStillInTheDrawer())
            ->where('order_payments.created_at', '>=', $prevEnd)
            ->where('order_payments.created_at', '<=', $now)
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'order_payments.payment_method_id')
            ->leftJoin('customer_orders as co', 'co.id', '=', 'order_payments.customer_order_id')
            ->orderBy('order_payments.created_at')
            // `select()` TRƯỚC `selectRaw()`, rồi `get()` KHÔNG tham số. Truyền
            // cột vào `get([...])` sau khi đã có `selectRaw` thì Laravel **bỏ
            // qua im lặng** danh sách ấy (`onceWithColumns` chỉ áp khi
            // `$this->columns` còn null) — hệ quả là `pm.code` biến mất và mọi
            // dòng thành `is_cash: false`. Test gap sẵn có bắt được ngay.
            ->select([
                'order_payments.id',
                'order_payments.customer_order_id as order_id',
                'order_payments.amount',
                'order_payments.created_at',
                'pm.code as method_code',
                'pm.name as method_label',
                'co.order_code as order_code',
            ])
            ->selectRaw(
                '('.self::UNATTRIBUTED_NET_SQL.') as net_amount',
                self::statusesThatMayStillHoldMoney()
            )
            ->get();

        // `amount` là NET, không phải số gộp: khoản 5000 đã hoàn 1000 thì két
        // đang giữ 4000, và thu ngân đối soát với TIỀN THẬT trong ngăn kéo. Trả
        // về 5000 ở đây là bắt họ đối chiếu với một con số không tồn tại (#2744).
        $payments = $rows->map(fn ($r) => [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'order_code' => $r->order_code,
            'amount' => round((float) $r->net_amount, 2),
            'gross_amount' => (float) $r->amount,
            'method_code' => $r->method_code,
            'method_label' => $r->method_label,
            'is_cash' => $r->method_code === 'cash',
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->values();

        return [
            'previous_session' => [
                'id' => $prevSession->id,
                'session_code' => $prevSession->session_code,
                'ended_at' => $prevEnd->toIso8601String(),
            ],
            'gap_window' => [
                'from' => $prevEnd->toIso8601String(),
                'to' => $now->toIso8601String(),
            ],
            'currency_code' => $currency,
            'payments' => $payments->all(),
            'totals' => [
                'count' => $payments->count(),
                'cash_amount' => (float) $payments->where('is_cash', true)->sum('amount'),
                'non_cash_amount' => (float) $payments->where('is_cash', false)->sum('amount'),
            ],
        ];
    }

    /**
     * #2696 — mở ca kế mà còn đơn treo tiền thì BÁO, đừng để nó im.
     *
     * Ruling chủ dự án 2026-08-13: mọi lỗi phải tới được nhân viên (#2694). Đây
     * là mảnh của ruling đó cho tiền treo — trước bản vá này, một đơn `checkout`
     * mồ côi (bàn đã nhả) không chặn gì và không hiện gì; ORD-2026-0191 nằm im
     * 17 giờ với ¥700 và chỉ lộ ra khi có người đi grep production.
     *
     * FAIL-OPEN tuyệt đối: hỏng ở đây KHÔNG được chặn lượt mở ca. Thu ngân
     * không mở được ca thì quán không bán được — tệ hơn hẳn một cảnh báo trượt.
     * Cùng bất biến với `AlertController` phía máy trạm.
     *
     * Khử trùng theo NGÀY KINH DOANH + ca: cùng một đơn vắt qua ba ranh ca thì
     * kêu lại ở mỗi lần mở ca (nó vẫn đang treo tiền), nhưng mở đi mở lại cùng
     * một ca không đẻ thêm chuông.
     */
    private function notifyUnresolvedOrdersAtOpen(TillSession $session): void
    {
        try {
            $branchId = (string) $session->branch_id;
            // #2724 — ranh ca đo trên till CỦA CA VỪA MỞ, không phải MAIN cứng.
            // `open()` nhận `till_code` tự do, nên hỏi MAIN là hỏi nhầm két.
            $till = $session->till ?? Till::find($session->till_id);
            $preview = $till === null
                ? $this->unresolvedOrdersPreview($branchId)
                : $this->unresolvedOrdersPreviewForTill($till);

            if (($preview['totals']['count'] ?? 0) === 0) {
                return;
            }

            $branch = Branch::find($branchId);
            if ($branch === null) {
                return;
            }

            $brand = Brand::where('console_brand_id', $branch->console_brand_id)->first();
            if ($brand === null) {
                return;
            }

            $currency = (string) $preview['currency_code'];
            $totals = $preview['totals'];

            app(NotificationDispatcher::class)->toRole(
                new NotificationRequest(
                    type: 'till.unresolved_orders',
                    params: [
                        'shop_name' => $branch->name ?? '(unknown)',
                        'order_count' => $totals['count'],
                        // #2720 — tiền phải là CHUỖI đã format theo minor unit của
                        // currency trước khi vào template. Trước bản vá, float thô
                        // đi thẳng vào `{{outstanding_amount}}` và chuông gửi cho
                        // shop-manager mang nguyên văn `0.30000000000000004`.
                        'outstanding_amount' => $this->formatMoneyParam($totals['outstanding_amount'], $currency),
                        'currency_code' => $currency,
                        // #2721 — "còn thiếu" và "chỉ cần đóng đơn" là HAI việc.
                        // Đơn thu đủ mà kẹt `paying` vẫn phải hiện (nhân viên phải
                        // đóng nó) nhưng không được đếm vào số tiền còn thiếu.
                        'outstanding_order_count' => $totals['outstanding_count'],
                        'pending_close_count' => $totals['pending_close_count'],
                        'order_codes' => $this->unresolvedOrderCodesParam($preview['orders'], $currency),
                    ],
                    organizationId: (string) $session->organization_id,
                    actor: auth()->user() instanceof User ? auth()->user() : null,
                    subject: $session,
                    idempotencyKey: "till.unresolved_orders:{$session->id}",
                    // #2721 — không còn đồng nào thiếu ⇒ đây là việc dọn sổ, không
                    // phải báo động tiền. Vẫn kêu (đơn phải được đóng), nhưng không
                    // kêu ngang với một ca đang mất tiền thật.
                    priority: $totals['outstanding_count'] === 0 ? 'low' : null,
                    // #2754 — bản copy riêng cho ca "đã thu đủ, chỉ cần đóng
                    // đơn" (#2737 seed nó, nhưng tới đây mới có đường CHỌN).
                    // `type` KHÔNG đổi theo nhánh: đây vẫn là một sự kiện, và
                    // đổi type sẽ làm mọi bộ lọc theo type bỏ sót loại mới mà
                    // không báo gì.
                    templateKey: $totals['outstanding_count'] === 0
                        ? 'till.unresolved_orders.pending_close'
                        : null,
                    // #2727 — bucket gộp là ngày kinh doanh CỦA CA, không phải của
                    // lúc hàm chạy: `openFromWorkstation()` sync trễ (máy trạm mất
                    // Cloud qua đêm) sẽ gộp ca HÔM QUA vào bucket HÔM NAY.
                    aggregationKey: "till.unresolved_orders:branch:{$branchId}:date:"
                        .$this->businessDateOfSession($session),
                ),
                // #2450 — tiền treo là chuyện của quản lý quán VÀ admin tổ chức.
                // Không suy từ thứ bậc vai: đo trên production 2026-08-11 cho
                // thấy hỏi mỗi `shop-manager` ra 0 người ở doanh nghiệp chỉ có
                // chủ quán, tức không báo cho ai cả.
                role: ['shop-manager', 'org-admin'],
                scopeKey: 'branch_id',
                scopeId: $branchId,
                brand: $brand,
            );
        } catch (\Throwable $e) {
            Log::warning('till.unresolved_orders notify failed', [
                'till_session_id' => $session->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * #2696 — đơn còn treo tiền, hiện lúc MỞ ca kế.
     *
     * Anh em của {@see gapPreview()} và cố ý dùng chung màn hình mở ca, nhưng
     * hai thứ này ĐO HAI CÁI KHÁC NHAU — đừng gộp vào một bảng:
     *
     *   gapPreview  → khoản thu `till_session_id IS NULL`: **tiền đã vào**,
     *                 chỉ chưa gắn ca. Việc của thu ngân là gắn nó vào ca.
     *   ở đây       → **đơn** còn `paying`/`checkout`: tiền có thể **chưa vào**.
     *                 Việc của thu ngân là truy xem khách trả chưa, rồi đóng
     *                 hoặc huỷ đơn.
     *
     * Trộn chung sẽ khiến thu ngân "gắn ca" cho một khoản chưa hề tồn tại.
     *
     * ## Ngưỡng: RANH CA, không phải số giờ (ruling chủ dự án 2026-08-13)
     *
     * Đơn tính là kẹt khi nó còn `paying`/`checkout` mà KHÔNG sinh ra trong ca
     * đang chạy. KHÔNG đo bằng số giờ trôi qua: đơn kẹt 20 phút nhưng vắt qua
     * lúc đóng ca vẫn phải hiện, còn đơn mở 18:00 và đóng 22:00 trong CÙNG một
     * ca thì không. Ngưỡng giờ thì phải bịa ra một con số, và sai theo hướng nào
     * cũng dạy người ta bỏ qua cảnh báo.
     *
     * ## Đơn sinh TRONG cửa sổ gap cũng thuộc diện (#2723)
     *
     * Bản đầu cắt ở `created_at < prev_end` và bỏ lọt đúng cái ca tệ nhất: đơn
     * web/tablet đến khi két đã đóng (qua đêm) sinh SAU prev_end nhưng TRƯỚC lúc
     * mở ca kế. Đo được: két đóng −10h, đơn `paying` sinh −7h ⇒ preview lúc mở
     * ca = 0, đơn chỉ lộ ở lần mở SAU NỮA — trễ trọn một ranh ca, và ở màn ĐÓNG
     * ca nó cũng vô hình (`unpaid_carry` coi đơn có payment succeeded là "paid").
     * Ranh trên vì thế là **lúc ca đang chạy mở** (hoặc `now()` khi chưa có ca
     * nào mở — tức đang đứng ở màn mở ca), không phải prev_end. `gapPreview` vốn
     * đã quét cửa sổ `(prev_end, now]` cho TIỀN; đây là anh em của nó cho ĐƠN.
     *
     * ## Đi từ ĐƠN, không đi từ BÀN — đây là chỗ dễ hụt nhất
     *
     * Một phép quét khởi từ `tables.current_order_id` sẽ bỏ sót đúng ca tệ nhất:
     * ORD-2026-0191 trên production kẹt `checkout` 17 giờ với ¥700 trong khi bàn
     * đã về `free` và `current_order_id` đã rỗng — không chặn gì, không hiện gì,
     * không ai thấy. Query ở đây khởi từ `customer_orders` nên đơn mồ côi vẫn
     * nằm trong diện.
     *
     * Chưa có ca kết thúc nào ⇒ không bounding được ⇒ rỗng, cùng quy ước với
     * `gapPreview()`.
     *
     * @param  string|null  $tillCode  két cần đo ranh ca; null ⇒ tự giải theo
     *                                 {@see shiftBoundaryTillForBranch()} (#2724).
     * @return array{previous_session: ?array, scan_window: ?array, currency_code: string, orders: array<int, array<string, mixed>>, totals: array{count: int, outstanding_amount: float, outstanding_count: int, pending_close_count: int}}
     */
    public function unresolvedOrdersPreview(string $branchId, ?string $tillCode = null): array
    {
        $till = $this->shiftBoundaryTillForBranch($branchId, $tillCode);

        // #2745 vòng 2 — mã do caller khai mà không có thật ⇒ RỖNG, không tạo
        // két. Cùng ngữ nghĩa với `gapPreview()`; xem `existingTillForBranch()`.
        if ($till === null) {
            return $this->emptyUnresolvedPreview($branchId);
        }

        return $this->unresolvedOrdersPreviewForTill($till);
    }

    /**
     * Hình dạng RỖNG của `unresolvedOrdersPreview()` khi chưa có két nào để đo.
     *
     * @return array{previous_session: ?array, scan_window: ?array, currency_code: string, orders: array<int, array<string, mixed>>, totals: array{count: int, outstanding_amount: float, outstanding_count: int, pending_close_count: int}}
     */
    private function emptyUnresolvedPreview(string $branchId, ?Till $till = null): array
    {
        return [
            'previous_session' => null,
            'scan_window' => null,
            'currency_code' => app(BranchCurrency::class)->codeFor($branchId)
                ?: ($till->default_currency_code ?? 'JPY'),
            'orders' => [],
            'totals' => [
                'count' => 0,
                'outstanding_amount' => 0.0,
                'outstanding_count' => 0,
                'pending_close_count' => 0,
            ],
        ];
    }

    /**
     * {@see unresolvedOrdersPreview()} khi ĐÃ biết két — đường mà
     * `notifyUnresolvedOrdersAtOpen()` đi, để chuông và panel không bao giờ đo
     * hai ranh ca khác nhau cho cùng một lượt mở.
     *
     * @return array{previous_session: ?array, scan_window: ?array, currency_code: string, orders: array<int, array<string, mixed>>, totals: array{count: int, outstanding_amount: float, outstanding_count: int, pending_close_count: int}}
     */
    private function unresolvedOrdersPreviewForTill(Till $till): array
    {
        $branchId = (string) $till->branch_id;
        $currency = app(BranchCurrency::class)->codeFor($branchId)
            ?: ($till->default_currency_code ?? 'JPY');

        $empty = $this->emptyUnresolvedPreview($branchId, $till);

        $prev = $this->previousTerminalSessionForTill($till);
        if ($prev === null) {
            return $empty;
        }

        /** @var TillSession $prevSession */
        $prevSession = $prev['session'];
        $prevEnd = $prev['end'];
        $cutoff = $this->unresolvedScanCutoffFor($till, $prevEnd);

        $previousSession = [
            'id' => $prevSession->id,
            'session_code' => $prevSession->session_code,
            'ended_at' => $prevEnd->toIso8601String(),
        ];
        $scanWindow = [
            'previous_shift_ended_at' => $prevEnd->toIso8601String(),
            'until' => $cutoff->toIso8601String(),
        ];

        // Qua CỔNG đã inject, không `app()` và không tự truy `customer_orders`.
        // Cổng trả dữ liệu cấp đơn; phần tiền đã thu thì Payments tự tính trên
        // bảng của chính mình ngay dưới đây.
        $orders = $this->orders->unresolvedForBranchBefore(
            $branchId,
            $cutoff->toIso8601String(),
        );

        if ($orders === []) {
            return array_merge($empty, [
                'previous_session' => $previousSession,
                'scan_window' => $scanWindow,
            ]);
        }

        // #2718 — tiền ĐÃ THU RÒNG, một định nghĩa duy nhất:
        // `OrderPayment::netCollectedForOrder()`, đúng vị ngữ mà void-guard #816
        // dùng. Vị ngữ cũ (`status='succeeded' AND refund_of_id IS NULL`) đọc sai
        // sổ refund của repo — hàng gốc GIỮ +X rồi flip sang `refunded` KỂ CẢ khi
        // refund một phần, hàng refund là −X — nên một đơn 4.720 đã thu 3.720 và
        // hoàn 500 hiện ra "đã thu 0 / còn thiếu 4.720" thay vì 3.220 / 1.500, và
        // rider #821 (`settles_payment_id`, tiền của đơn KHÁC) kẹp outstanding về
        // 0 đúng chỗ chuông cần kêu. Viết vị ngữ tiền thứ hai ở đây là hẹn giờ
        // cho hai con số trôi lệch nhau; một lượt gọi mỗi đơn là giá phải trả, và
        // tập này bị chặn bởi số đơn còn treo của MỘT chi nhánh (đơn vị hàng chục).
        $rows = array_map(function ($o): array {
            $paid = round(OrderPayment::netCollectedForOrder($o->orderId), 2);
            // Kẹp ở 0: thu vượt là chuyện khác (#2587), không phải nợ.
            $outstanding = max(0.0, round($o->totalAmount - $paid, 2));

            return [
                'id' => $o->orderId,
                'order_code' => $o->orderCode,
                'status' => $o->status,
                'total_amount' => $o->totalAmount,
                'paid_amount' => $paid,
                'outstanding_amount' => $outstanding,
                // #2721 — đã thu đủ, chỉ kẹt trạng thái: vẫn phải hiện (ai đó
                // phải đóng đơn) nhưng KHÔNG phải tiền thiếu. Panel tô cảnh báo
                // theo cờ này, đừng suy lại từ `outstanding_amount == 0`.
                'needs_close_only' => $outstanding <= 0.0,
                // Bàn đã nhả hay chưa — dấu hiệu đơn mồ côi, thứ nhân viên
                // không thể tự thấy ở màn bàn.
                'table_released' => $o->tableReleased,
                'created_at' => $o->createdAt,
            ];
        }, $orders);

        $pendingCloseCount = count(array_filter($rows, static fn (array $r): bool => $r['needs_close_only']));

        return [
            'previous_session' => $previousSession,
            'scan_window' => $scanWindow,
            'currency_code' => $currency,
            'orders' => $rows,
            'totals' => [
                'count' => count($rows),
                // Làm tròn MỘT lần ở tổng: `array_sum` thô trên float là chỗ đẻ
                // ra `0.30000000000000004` (#2720).
                'outstanding_amount' => round((float) array_sum(array_column($rows, 'outstanding_amount')), 2),
                'outstanding_count' => count($rows) - $pendingCloseCount,
                'pending_close_count' => $pendingCloseCount,
            ],
        ];
    }

    /**
     * Ranh TRÊN của phép quét đơn treo (#2723).
     *
     * Ca đang mở ⇒ mốc mở ca ấy: đơn sinh trong ca đang chạy là việc của ca
     * này, không phải "kẹt qua ranh". Chưa có ca nào mở (đứng ở màn mở ca) ⇒
     * `now()`, nên đơn sinh trong cửa sổ gap không còn vô hình trọn một ca.
     * Không bao giờ lùi trước `prev_end` — đó vẫn là ranh dưới đã bảo đảm.
     */
    private function unresolvedScanCutoffFor(Till $till, CarbonInterface $prevEnd): CarbonInterface
    {
        $openedAt = null;
        if ($till->current_session_id !== null) {
            $openedAt = TillSession::query()
                ->whereKey($till->current_session_id)
                ->first(['id', 'opened_at'])?->opened_at;
        }

        $cutoff = $openedAt === null ? now() : Carbon::parse($openedAt);

        return $cutoff->lt($prevEnd) ? Carbon::parse($prevEnd) : $cutoff;
    }

    /**
     * Két dùng để đo ranh ca của một chi nhánh (#2724).
     *
     * `resolveTillForBranch()` trả MAIN **cứng** (và tạo nó nếu chưa có), trong
     * khi `open()` nhận `till_code` tự do. Đo được: chi nhánh chỉ chạy till
     * `SUB` — SUB đóng 1h trước, đơn treo sinh 2h trước — cho preview 0 và 0
     * thông báo, vì MAIN vừa được tạo ra chưa có ca nào; và khi MAIN đóng 10
     * ngày trước còn SUB đóng 1h trước thì ranh đo được là mốc 10 ngày cũ.
     *
     * Thứ tự giải: till_code do caller chỉ định > két đang có ca mở > két có ca
     * kết thúc GẦN NHẤT (cùng comparator plan-046 P6-4 với
     * `previousTerminalSessionForTill`) > MAIN. Chi nhánh chưa có két nào thì
     * vẫn tạo MAIN mặc định v1 — giữ nguyên hành vi cũ.
     *
     * CHỈ dùng cho phép quét ĐƠN. `gapPreview()` **không** được gọi vào đây: nó
     * là đầu ĐỌC của một cặp đọc/ghi và phải giải ra đúng cái két mà
     * `claimGapPayments()` sẽ ghi, nếu không tiền tích trên màn hình sẽ không
     * vào ca nào.
     */
    private function shiftBoundaryTillForBranch(string $branchId, ?string $tillCode = null): ?Till
    {
        // #2745 vòng 2 — nhánh này nhận `?till_code=` THẲNG từ caller
        // (`GET /pos/till/unresolved-orders`), nên `firstOrCreate` ở đây là
        // cùng một lỗ với `gapPreview()`: một request chỉ-đọc dựng được hàng
        // `tills` mang mã bịa. Vòng 1 vá gap-preview và bỏ sót cửa này — tệ
        // hơn, còn thêm comment khẳng định gap-preview là đường DUY NHẤT.
        //
        // Két rác ở đây nguy hiểm hơn ở gap-preview: nếu chi nhánh chưa có két
        // nào, hàng rác trở thành két DUY NHẤT, và lượt quét sau không truyền
        // `till_code` rơi vào shortcut `count === 1` ngay dưới — tức nó thành
        // két đo RANH CA của cả chi nhánh.
        //
        // `null` = mã không có thật ⇒ người gọi trả preview rỗng.
        if ($tillCode !== null && $tillCode !== '') {
            return $this->existingTillForBranch($branchId, $tillCode);
        }

        $tills = Till::query()
            ->where('branch_id', $branchId)
            ->orderBy('till_code')
            ->get();

        if ($tills->isEmpty()) {
            return $this->resolveTillForBranch($branchId);
        }

        if ($tills->count() === 1) {
            return $tills->first();
        }

        $withOpenSession = $tills->first(static fn (Till $t): bool => $t->current_session_id !== null);
        if ($withOpenSession !== null) {
            return $withOpenSession;
        }

        $latest = $this->latestTerminalOf(
            $this->terminalSessionsFor($tills->pluck('id')->map(static fn ($id): string => (string) $id)->all())
        );
        if ($latest !== null) {
            $till = $tills->first(
                static fn (Till $t): bool => (string) $t->id === (string) $latest['session']->till_id
            );
            if ($till !== null) {
                return $till;
            }
        }

        return $tills->first(static fn (Till $t): bool => $t->till_code === self::DEFAULT_TILL_CODE)
            ?? $tills->first();
    }

    /**
     * Ngày kinh doanh CỦA CA cho khoá gộp chuông (#2727).
     *
     * `business_date` đã được đóng dấu theo timezone chi nhánh lúc mở ca, nên nó
     * là thứ duy nhất đúng khi lượt sync UP của máy trạm tới trễ một ngày. Chỉ
     * khi ca không mang dấu ấy mới rơi về đồng hồ hiện tại.
     */
    private function businessDateOfSession(TillSession $session): string
    {
        $businessDate = $session->business_date;

        if ($businessDate instanceof \DateTimeInterface) {
            return Carbon::instance($businessDate)->toDateString();
        }

        if (is_string($businessDate) && $businessDate !== '') {
            return Carbon::parse($businessDate)->toDateString();
        }

        return BusinessClock::businessDateAt((string) $session->branch_id, now());
    }

    /**
     * Tiền đưa vào template thông báo (#2720).
     *
     * Template nội suy NGUYÊN VĂN `(string) $param`, nên một float thô đi thẳng
     * ra chuông: probe đo được `0.30000000000000004` trong nội dung gửi tới
     * shop-manager. Số lẻ theo minor unit của chính currency (JPY/VND 0 lẻ,
     * USD/EUR 2 lẻ) qua {@see ZeroDecimalCurrency} — cùng nguồn chân lý với
     * Stripe và rounding, không phải một bảng chép tay thứ tư.
     *
     * KHÔNG chèn dấu phân nhóm: một chuỗi `1.000,00` đọc sang ja/en là một số
     * khác hẳn, mà params thì dùng chung cho cả ba locale.
     */
    private function formatMoneyParam(float $amount, ?string $currencyCode): string
    {
        $decimals = ZeroDecimalCurrency::contains($currencyCode) ? 0 : 2;

        return number_format(round($amount, $decimals), $decimals, '.', '');
    }

    /**
     * Nhãn một đơn trong danh sách của chuông (#2720).
     *
     * `order_code` NULL (đơn chưa được cấp mã) trước đây cast thành chuỗi rỗng
     * nên danh sách hiện ra "…, , …". Rơi về id rút gọn — vẫn tra được — và kèm
     * số tiền còn thiếu để người đọc phân biệt đơn thiếu tiền với đơn chỉ cần
     * đóng (#2721) mà không cần thêm placeholder vào template.
     *
     * @param  array<string, mixed>  $order
     */
    private function unresolvedOrderLabel(array $order, string $currencyCode): string
    {
        $code = $order['order_code'] ?? null;
        $label = is_string($code) && $code !== ''
            ? $code
            : '#'.substr((string) $order['id'], 0, 8);

        // #2754 — chỉ đóng dấu tiền lên đơn THẬT SỰ còn thiếu. Đơn chỉ-cần-đóng
        // trước đây ra `T-042 (0)`, và với ca pending-close-only thì cả danh
        // sách thành một hàng số 0 — lẻn một con số tiền vào bản copy vốn được
        // viết để KHÔNG nói về tiền. Mã trần ở đây mang đúng nghĩa "không thiếu
        // đồng nào", tức vẫn giữ được phép phân biệt mà #2721 cần.
        $outstanding = (float) $order['outstanding_amount'];
        if ($outstanding <= 0.0) {
            return $label;
        }

        return $label.' ('.$this->formatMoneyParam($outstanding, $currencyCode).')';
    }

    /**
     * `{{order_codes}}` — danh sách rút gọn, kèm phần đuôi ĐẾM ĐƯỢC (#2739).
     *
     * Cắt ở 5 để chuông không thành bức tường chữ, nhưng bản cắt trần **đọc như
     * danh sách đầy đủ**: một ca treo 40 đơn hiện y hệt một ca treo 5. Người
     * nhận quyết định có xuống quầy hay không dựa vào đúng dòng này, nên con số
     * bị giấu là con số điều hướng sai hành động. `+N` nói rõ còn bao nhiêu nữa.
     *
     * `order_count` đã có sẵn trong params từ #2720 nên hậu tố này là dư thừa
     * có chủ đích — dư ở ĐÚNG chỗ người đọc đang nhìn, thay vì bắt họ đối chiếu
     * hai placeholder cách nhau vài câu.
     *
     * @param  list<array<string, mixed>>  $orders
     */
    private function unresolvedOrderCodesParam(array $orders, string $currencyCode): string
    {
        $shown = array_slice($orders, 0, self::UNRESOLVED_ORDER_CODES_SHOWN);

        $list = implode(', ', array_map(
            fn (array $o): string => $this->unresolvedOrderLabel($o, $currencyCode),
            $shown,
        ));

        $hidden = count($orders) - count($shown);

        return $hidden > 0 ? $list.' +'.$hidden : $list;
    }

    /**
     * Plan-044 R2 — close-screen order summary.
     *
     * `paid` = distinct orders with a succeeded (non-refund) payment attributed to
     * THIS session — the orders whose money the shift holds (a partially-paid but
     * still-active order counts as paid: its money is in the drawer). `unpaid_carry`
     * = the branch's still-active orders with NO succeeded payment at all — the set
     * that simply stays open and is served in the next shift (R9: never voided at
     * close). Read-only; the drawer reconcile is unchanged (payment-driven).
     *
     * @return array{paid_orders_count: int, paid_orders_total: float, unpaid_carry_count: int, unpaid_carry_orders: array<int, array<string, mixed>>}
     */
    public function orderSummary(TillSession $session): array
    {
        $paidQuery = OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatusEnum::Succeeded->value);

        $paidOrderCount = (clone $paidQuery)->distinct()->count('customer_order_id');
        $paidOrdersTotal = (float) (clone $paidQuery)->sum('amount');

        // #1647 — hai lượt thay cho một `NOT EXISTS` vắt qua hai module: Ordering
        // trả đơn ĐANG MỞ của chi nhánh, Payments tự loại những đơn đã có tiền
        // vào trên bảng của mình. Tập trung gian bị chặn theo phạm vi (đơn đang
        // mở của MỘT chi nhánh — vài chục), nên đây không phải đánh đổi hiệu năng.
        $openOrders = $this->orders->openForBranch((string) $session->branch_id);

        $settledOrderIds = $openOrders === [] ? [] : OrderPayment::query()
            ->whereIn('customer_order_id', array_map(fn ($o) => $o->orderId, $openOrders))
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatusEnum::Succeeded->value)
            ->distinct()
            ->pluck('customer_order_id')
            ->all();
        $settledOrderIds = array_flip(array_map('strval', $settledOrderIds));

        $unpaidOrders = collect($openOrders)
            ->reject(fn ($o) => isset($settledOrderIds[$o->orderId]))
            ->map(fn ($o) => [
                'id' => $o->orderId,
                'order_code' => $o->orderCode,
                'status' => $o->status,
                'total_amount' => $o->totalAmount,
                'outstanding_amount' => $o->totalAmount, // unpaid → outstanding == total
                'created_at' => $o->createdAt,
            ])
            ->values();

        return [
            'paid_orders_count' => $paidOrderCount,
            'paid_orders_total' => $paidOrdersTotal,
            'unpaid_carry_count' => $unpaidOrders->count(),
            'unpaid_carry_orders' => $unpaidOrders->all(),
        ];
    }

    /**
     * Resolve (or create v1-default MAIN) Till for a branch.
     * v1: one Till per branch. Service-level findOrCreate so cashiers can
     * open a shift on a brand-new branch without an admin setup step.
     */
    public function resolveTillForBranch(string $branchId, string $tillCode = self::DEFAULT_TILL_CODE): Till
    {
        return Till::firstOrCreate(
            ['branch_id' => $branchId, 'till_code' => $tillCode],
            $this->defaultTillAttributesForBranch($branchId)
        );
    }

    /**
     * Cùng phép giải, nhưng KHÔNG tạo — dành cho đường ĐỌC (#2745).
     *
     * `resolveTillForBranch()` cố ý `firstOrCreate` để thu ngân mở được ca ở
     * một chi nhánh mới toanh mà không cần admin dựng két trước. Đó là hành vi
     * đúng cho đường GHI và **sai** cho đường đọc: `GET /pos/till/gap-preview`
     * nhận `?till_code=` tự do từ caller, nên một request chỉ-đọc đẻ ra được
     * hàng `tills` mang mã do người gọi bịa.
     *
     * Không mất tiền ngay, nhưng két rác không đứng yên: nó lọt vào comparator
     * "két có ca kết thúc gần nhất" ({@see shiftBoundaryTillForBranch()}) và bẻ
     * ranh ca của cả chi nhánh — đúng lớp lỗi #2724 vừa vá. Và luật chung thì
     * không cần viện đến ca cụ thể: đường đọc không được có tác dụng phụ ghi.
     */
    public function existingTillForBranch(string $branchId, string $tillCode = self::DEFAULT_TILL_CODE): ?Till
    {
        return Till::query()
            ->where('branch_id', $branchId)
            ->where('till_code', $tillCode)
            ->first();
    }

    /**
     * Plan-046 R8 (C1) — is a chain awaiting continuation on any of the
     * branch's tills? A handover SETTLES the shift (clearing
     * tills.current_session_id), so an open-shift check misses the window
     * between a handover and the next open — during which an admin could flip
     * currency/rounding and split one chain across two currencies. True when a
     * till's most-recent terminal session is a handover (the chain stays open
     * until its final close). Shared by the ShopOrderSettingsController guards
     * and the admin pre-flight status endpoint (#1130) so the UI can never
     * disagree with the 409.
     */
    /**
     * Plan-044 R2 — operator-confirmed gap-payment claim, run INSIDE open()'s
     * transaction (atomic with the shift open; a claim failure rolls the open
     * back — R8). Stamps `till_session_id = new` on the claimed payments that are
     * genuinely eligible: branch-owned, still NULL, succeeded non-refund, in the
     * gap window `(prev_end, opened_at]`, and drawer-collected (plan-054 D10 —
     * see collectedAtTheDrawer()). **Cash is stamped too** — it is held
     * aside by staff (not in the opening float), so attributing it to this shift
     * is correct; the "held-separately" fact is a UI/process guarantee recorded in
     * the audit, NOT a backend filter (DESIGN R2 §3). Ineligible / foreign / unknown
     * ids are silently skipped, never a 422 (attribution must not dead-letter open).
     *
     * #2724 — chỗ này KHÔNG mang bệnh "MAIN cứng": `$till` là két đã `lockTill()`
     * theo `till_code` mà `open()` nhận, nên cửa sổ đã đo đúng két đang mở. Đã
     * kiểm, không sửa gì — ghi lại để lần audit sau khỏi đi tìm lần nữa.
     *
     * Đây là ĐƯỜNG GHI của cặp với `gapPreview()`. Hai đầu phải giải ra CÙNG một
     * két, nếu không sẽ có khoản tiền được tích trên màn hình rồi bị lượt claim
     * từ chối đóng dấu — mất hút khỏi mọi ca. Vì thế `gapPreview()` cố ý KHÔNG
     * tự đoán két; xem docblock của nó.
     *
     * @param  list<string>  $claimedIds
     */
    private function claimGapPayments(TillSession $session, Till $till, array $claimedIds, bool $ack): int
    {
        $claimedIds = array_values(array_unique(array_filter($claimedIds)));
        if (empty($claimedIds)) {
            return 0;
        }

        // Window lower bound. No prior terminal session → no bounded gap → skip
        // (never sweep unbounded history), mirroring gapPreview.
        $prev = $this->previousTerminalSessionForTill($till);
        if ($prev === null) {
            return 0;
        }
        $prevEnd = $prev['end'];

        // Vị ngữ PHẢI khớp từng chữ với `gapPreview()` — cùng hai helper, không
        // phải hai bản chép tay tình cờ giống nhau. Lệch một điều kiện là
        // preview hiện khoản mà claim từ chối đóng dấu (#2744).
        $eligible = OrderPayment::query()
            ->whereIn('order_payments.id', $claimedIds)
            ->where('order_payments.branch_id', $session->branch_id)
            ->whereNull('order_payments.till_session_id')
            ->where($this->collectedAtTheDrawer())
            ->whereNull('order_payments.refund_of_id')
            ->whereIn('order_payments.status', self::statusesThatMayStillHoldMoney())
            ->where($this->netStillInTheDrawer())
            ->where('order_payments.created_at', '>=', $prevEnd)
            ->where('order_payments.created_at', '<=', $session->opened_at)
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'order_payments.payment_method_id')
            ->select(['order_payments.id', 'order_payments.amount', 'pm.code as method_code'])
            ->selectRaw(
                '('.self::UNATTRIBUTED_NET_SQL.') as net_amount',
                self::statusesThatMayStillHoldMoney()
            )
            ->get();

        if ($eligible->isEmpty()) {
            return 0;
        }

        // The ack is what tells us WHERE the gap cash physically is, and it is the
        // only thing standing between a correct claim and double-counting:
        //   held aside  → not in the opening float → attributing it here is right
        //   in the drawer → already inside opening_float_amount → attributing it
        //                   again makes this shift over by exactly that amount,
        //                   since reconcile() derives 過不足 from till_session_id.
        // pos-web gates its submit on it, but a UI-only rule is no rule: the
        // workstation, an older pos-web build or a plain curl all reach this same
        // endpoint. Enforce it where the money is actually attributed.
        // NET, không phải gộp: một khoản tiền mặt 5000 đã hoàn 1000 chỉ còn 4000
        // nằm ngoài opening float — đó mới là số cần ack (#2744).
        $cashAmount = (float) $eligible->where('method_code', 'cash')->sum('net_amount');
        if ($cashAmount > 0 && ! $ack) {
            throw ValidationException::withMessages([
                'gap_cash_held_separately_ack' => __('Confirm the claimed gap cash was held separately from the opening float.'),
            ]);
        }

        $appliedIds = $eligible->pluck('id')->all();
        $originIds = array_map(static fn (mixed $id): string => (string) $id, $appliedIds);

        // Hàng HOÀN đi theo khoản gốc, không được để mồ côi (#2744 vòng 2).
        //
        // `reconcile()` (#523) tính kỳ vọng ngăn kéo bằng SUM(amount) trên các
        // hàng mang `till_session_id` của ca. Đóng dấu MỖI hàng gốc (+1.000) mà
        // bỏ hàng hoàn (−300) lại NULL thì ca này bị đòi 1.000 trong khi thu
        // ngân ack 700 và cầm 700 thật ⇒ **ca short đúng bằng số đã hoàn**, và
        // −300 vĩnh viễn không ca nào gánh. Đó là đổi "700 vô hình" (bug gốc)
        // lấy "1.000 phồng + biến động 300 lúc đóng ca" — vẫn sai tiền.
        //
        // Chỉ nhận hàng hoàn còn NULL: hàng đã gắn ca khác là tiền ca đó đã
        // gánh, cướp về đây là bẻ sổ của một ca đã chốt.
        //
        // Topology LAN đã đúng từ trước — `POST /workstation/payments/{id}/attribution`
        // đóng dấu hàng hoàn vô điều kiện. Bản này kéo đường Cloud-direct về
        // khớp với nó, thay vì để hai đường xử cùng kịch bản theo hai kiểu.
        $refundIds = $originIds === [] ? [] : OrderPayment::query()
            ->whereIn('refund_of_id', $originIds)
            ->whereNull('till_session_id')
            ->whereIn('status', self::statusesThatMayStillHoldMoney())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->orderPaymentLedger->stampTillSessionIds(
            array_merge($originIds, $refundIds),
            (string) $session->id,
        );

        $session->logAudit('till_session.gap_claim', [
            'applied_ids' => $appliedIds,
            'refund_ids' => $refundIds,
            'skipped_ids' => array_values(array_diff($claimedIds, $appliedIds)),
            // NET, khớp con số thu ngân đã ack và đã đếm bằng tay. Ghi GROSS ở
            // đây từng làm sổ audit nói 1.000 trong khi ack nói 700 — hai con số
            // cho cùng một hành động là thứ không đối soát lại được.
            'total_amount' => (float) $eligible->sum('net_amount'),
            'cash_amount' => $cashAmount,
            'gross_amount' => (float) $eligible->sum('amount'),
            'held_separately_ack' => $ack,
            'actor_id' => Auth::id(),
        ]);

        return count($appliedIds);
    }

    // =========================================================================
    //  Write — Workflow
    // =========================================================================

    /**
     * Mở ca.
     *
     * @param  array{
     *     branch_id: string,
     *     organization_id: string,
     *     brand_id: string,
     *     till_code?: string,
     *     currency_code?: string,
     *     opening_counts: list<array{denomination_id: string, quantity: int}>,
     *     opening_note?: string,
     *     opened_by_id?: ?string,
     *     opener_name?: ?string,
     *   }  $data
     *
     * @throws HttpException 409 if till already has an open session.
     */
    public function open(array $data): TillSession
    {
        // session_code is globally unique; two opens on different tills can
        // still race on the same per-day number. Retry the whole transaction
        // so the regenerated code reads the now-committed row (fresh snapshot
        // each attempt) instead of surfacing a 500 to the cashier.
        for ($attempt = 1; ; $attempt++) {
            try {
                $opened = DB::transaction(function () use ($data) {
                    $till = $this->lockTill($data['branch_id'], $data['till_code'] ?? self::DEFAULT_TILL_CODE);

                    if ($till->current_session_id !== null) {
                        abort(response()->json([
                            'message' => 'A shift is already open on this till.',
                            'code' => 'SHIFT_ALREADY_OPEN',
                        ], 409));
                    }

                    // Single source of truth: shop_order_settings.currency_code (admin-web flips
                    // this; pos-web reads it everywhere). Fall through till default → JPY for
                    // legacy branches that pre-date the settings row.
                    // AUDIT FIX 3.6 (2026-07-14): lockForUpdate — serializes shift-open
                    // against the admin settings PATCH (which locks the same row inside
                    // its guard transaction), closing the race where a currency /
                    // tax-mode flip slipped between the guard's open-shift check and its
                    // write while this open() was in flight.
                    // #962 — cái khoá ấy giờ là Ý ĐỊNH ĐƯỢC KHAI của cổng, không phải
                    // một `->lockForUpdate()` lẻ ở đây: bỏ nó đi thì không test nào đỏ,
                    // nên nó phải nằm chỗ có test canh. Cổng BẮT BUỘC gọi trong
                    // transaction — lời gọi này ở trong `DB::transaction()` ngay trên.
                    $shopSetting = $this->branchOrderSettings->lockAndReadForBranch((string) $data['branch_id']);
                    $currency = ($shopSetting?->currencyCode) ?: ($till->default_currency_code ?? 'JPY');
                    // plan-043 — snapshot the tax-included mode at open (same
                    // pattern as default_currency_code) so shift reports are
                    // self-contained and consistent even if the flag flips later.
                    $pricesIncludeTaxAtOpen = $shopSetting?->pricesIncludeTax ?? false;
                    $openedAt = now();
                    $sessionCode = $this->generateSessionCode($openedAt);

                    // Plan-046 (R1) — chain continuation. Continue the running chain
                    // IFF the till's most-recent terminal session was a HANDOVER;
                    // otherwise (final / abandoned / expired / none, incl. legacy
                    // null) start a fresh chain. The resolver returns
                    // array{session, end}|null (P6-1) — access via ['session'].
                    $prev = $this->previousTerminalSessionForTill($till);
                    $continuesChain = $prev !== null
                        && $prev['session']->settlement_kind === TillSettlementKindEnum::Handover;
                    if ($continuesChain) {
                        $chainId = $prev['session']->chain_id ?: (string) Str::uuid();
                        $chainSequence = ((int) $prev['session']->chain_sequence) + 1;
                    } else {
                        $chainId = (string) Str::uuid();
                        $chainSequence = 1;
                    }

                    /** @var TillSession $session */
                    $session = TillSession::create([
                        'session_code' => $sessionCode,
                        'status' => TillSessionStatusEnum::Open->value,
                        // #1091 — business_date is the BRANCH's calendar day,
                        // not the app-timezone (UTC) day: a shift opened 08:00
                        // JST Saturday belongs to Saturday, even though UTC
                        // still says Friday.
                        'business_date' => BusinessClock::businessDateAt((string) $till->branch_id, $openedAt),
                        'default_currency_code' => $currency,
                        'prices_include_tax_at_open' => $pricesIncludeTaxAtOpen,
                        'opening_float_amount' => 0,
                        'opening_note' => $data['opening_note'] ?? null,
                        'opened_by_id' => $data['opened_by_id'] ?? Auth::id(),
                        'opener_name' => $data['opener_name'] ?? null,
                        'opened_at' => $openedAt,
                        'till_id' => $till->id,
                        'branch_id' => $data['branch_id'],
                        'brand_id' => $data['brand_id'],
                        'organization_id' => $data['organization_id'],
                        // plan-046 chain grouping (R1).
                        'chain_id' => $chainId,
                        'chain_sequence' => $chainSequence,
                    ]);

                    $float = $this->persistDenominationCounts(
                        $session,
                        $currency,
                        TillCountPhaseEnum::Opening,
                        $data['opening_counts'] ?? []
                    );

                    $session->update(['opening_float_amount' => $float]);

                    $till->update(['current_session_id' => $session->id]);

                    // Plan-044 R2 — NO automatic carry-over re-stamp (queue dropped).
                    // Orders created in the close→open gap stay NULL; unpaid orders
                    // remain `active` and are simply served in the next shift — order
                    // attribution is display-only, read by no reconcile query (DESIGN
                    // R2, proven cash-flow-neutral).
                    $session->logAudit('till_session_opened', [
                        'session_code' => $session->session_code,
                        'opening_float_amount' => (float) $float,
                        'currency' => $currency,
                    ]);

                    // Plan-044 R2 — operator-confirmed gap-payment claim. Attributes
                    // the cashier-confirmed gap payments (cash + non-cash) to THIS
                    // shift, atomically inside open() (R8). Cash is held aside by
                    // staff, not in the opening float, so claiming it is correct.
                    $gapClaimed = $this->claimGapPayments(
                        $session,
                        $till,
                        $data['claimed_gap_payment_ids'] ?? [],
                        (bool) ($data['gap_cash_held_separately_ack'] ?? false),
                    );

                    $result = $this->findById($session->id);
                    // Response-only, never persisted — declared as real properties on
                    // the model so Eloquent doesn't treat them as (non-existent)
                    // columns and try to write them on the next save().
                    $result->gapPaymentsClaimed = $gapClaimed;
                    // Plan-046 — transient chain-continuation flag for the open
                    // response so pos-web shows the "Tiếp tục chuỗi — ca N" banner +
                    // enforces the blind re-count (R3). chain_id/chain_sequence are
                    // persisted columns (read back by findById via the resource).
                    $result->continuesChain = $continuesChain;

                    return $result;
                });

                $this->notifyUnresolvedOrdersAtOpen($opened);

                return $opened;
            } catch (UniqueConstraintViolationException $e) {
                // Another till grabbed the same SHIFT-#### number first. Retry
                // with a freshly generated code; give up after a few attempts.
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Ghi nhận 入金/出金 giữa ca.
     *
     * @param  array{
     *     event_type: string,
     *     amount: float|int|string,
     *     reason?: ?string,
     *     currency_code?: string,
     *     occurred_at?: string,
     *     reference_no?: ?string,
     *     performed_by_id?: ?string,
     *   }  $data
     */
    public function recordCashEvent(TillSession $session, array $data): TillCashEvent
    {
        return DB::transaction(function () use ($session, $data) {
            $session = TillSession::lockForUpdate()->findOrFail($session->id);
            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open],
                'SHIFT_NOT_OPEN',
                'Cash events can only be recorded on an open shift.'
            );

            /** @var TillCashEvent $event */
            $event = TillCashEvent::create([
                'session_id' => $session->id,
                'event_type' => $data['event_type'],
                'amount' => (float) $data['amount'],
                'currency_code' => $data['currency_code'] ?? $session->default_currency_code ?? 'JPY',
                'reason' => $data['reason'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'performed_by_id' => $data['performed_by_id'] ?? Auth::id(),
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $session->logAudit('till_cash_event_recorded', [
                'event_type' => $event->event_type instanceof TillCashEventTypeEnum
                    ? $event->event_type->value
                    : $event->event_type,
                'amount' => (float) $event->amount,
            ]);

            return $event;
        });
    }

    /**
     * Lưu nháp inputs kết ca (open → closing). Idempotent: gọi lại overwrite.
     *
     * @param  array{
     *     closing_counts?: list<array{denomination_id: string, quantity: int}>,
     *     tender_details?: list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>,
     *     closing_note?: ?string,
     *   }  $data
     */
    public function saveDraft(TillSession $session, array $data): TillSession
    {
        return DB::transaction(function () use ($session, $data) {
            $session = TillSession::lockForUpdate()->findOrFail($session->id);
            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
                'SHIFT_NOT_OPEN',
                'Drafts can only be saved before the shift is settled.'
            );

            // Idempotent overwrite of any closing-phase counts.
            if (isset($data['closing_counts'])) {
                TillCashDenominationCount::where('session_id', $session->id)
                    ->where('count_phase', TillCountPhaseEnum::Closing->value)
                    ->delete();
                $this->persistDenominationCounts(
                    $session,
                    $session->default_currency_code ?? 'JPY',
                    TillCountPhaseEnum::Closing,
                    $data['closing_counts']
                );
            }

            // Settlement details: idempotent overwrite of declared figures only.
            // Final close() rewrites these with expected_amount + variance.
            if (isset($data['tender_details'])) {
                $this->persistTenderDetailsDraft($session, $data['tender_details']);
            }

            $update = ['status' => TillSessionStatusEnum::Closing->value];
            if (array_key_exists('closing_note', $data)) {
                $update['closing_note'] = $data['closing_note'];
            }
            if (array_key_exists('closing_cash_adjustment', $data)) {
                $update['closing_cash_adjustment_amount'] = $data['closing_cash_adjustment'] !== null
                    ? round((float) $data['closing_cash_adjustment'], 2)
                    : null;
            }
            $session->update($update);

            return $this->findById($session->id);
        });
    }

    /**
     * #552 — refuse to settle a shift that still holds a LIVE pending payment.
     *
     * A non-auto-confirm payment (card/QR) sits `pending` until its terminal
     * confirms, carrying `expires_at = created_at + 15min`. If the shift settles
     * first and the payment confirms afterwards, confirm() re-stamps it to the
     * open shift — but when NO shift is open it keeps THIS (now settled) shift's
     * stamp, retroactively mutating a reconciled Z-report. Blocking the settle
     * until every pending has resolved (confirmed → succeeded, or lapsed →
     * failed) closes that window.
     *
     * Only LIVE pendings (expires_at in the future) block: a row past its
     * `expires_at` is already dead (the `payments:expire-stale` sweeper fails it
     * every minute), so counting it would strand the cashier behind an abandoned
     * mid-shift tap.
     *
     * A NULL `expires_at` is deliberately treated as DEAD, not live. A live
     * pending always carries a +15min deadline; a NULL one is only reachable via
     * legacy/anomalous data — and the sweeper skips it (`whereNotNull`), so
     * counting it as live would block the close FOREVER with nothing able to
     * clear it. A permanent, un-clearable lock is worse than missing a guard on
     * a row that should not exist.
     *
     * Not called from manualSettle(): the manager recovery path for an `expired`
     * shift must always be able to close (its pendings are long dead anyway).
     */
    private function assertNoLivePendingPayments(TillSession $session): void
    {
        $livePending = OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->where('status', PaymentStatusEnum::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->count();

        if ($livePending > 0) {
            abort(response()->json([
                'message' => 'Cannot close the shift while payments are still awaiting confirmation. Wait for the terminal, or up to 15 minutes for them to expire, then close again.',
                'code' => 'PENDING_PAYMENTS_BLOCK_CLOSE',
                'pending_count' => $livePending,
            ], 409));
        }
    }

    /**
     * Chốt ca. Atomic + irreversible.
     *
     * @param  array{
     *     closing_counts: list<array{denomination_id: string, quantity: int}>,
     *     tender_details: list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>,
     *     closing_note?: ?string,
     *     closed_by_id?: ?string,
     *   }  $data
     */
    public function close(TillSession $session, array $data): TillSession
    {
        // Plan-046: the existing settle is now the FINAL close — it ends the
        // chain. Behaviour for a chain of one shift is identical to before.
        return $this->settleShift($session, $data, TillSettlementKindEnum::Final);
    }

    /**
     * Plan-046 (T2.1) — HANDOVER settle. Settles the current shift exactly like
     * close() but keeps the chain OPEN: stamps settlement_kind=handover so the
     * next open() on this till continues the same chain (R1). The client then
     * prints a single-shift 引き継ぎ slip and opens the next shift.
     *
     * @param  array<string, mixed>  $data
     */
    public function handover(TillSession $session, array $data): TillSession
    {
        return $this->settleShift($session, $data, TillSettlementKindEnum::Handover);
    }

    /**
     * Shared settle body for close()=final and handover() (plan-046). The ONLY
     * differences between the two are the settlement_kind stamped and the audit
     * action — every guard (status, pending-payments, variance-reason), the
     * denomination/tender persistence, the #552 revenue freeze, and the guarded
     * till-lock clear are identical. Also writes the immutable settlement_snapshot
     * (T2.5, R2) the chain aggregate sums.
     *
     * @param  array<string, mixed>  $data
     */
    private function settleShift(TillSession $session, array $data, TillSettlementKindEnum $kind): TillSession
    {
        return DB::transaction(function () use ($session, $data, $kind) {
            $till = $this->lockTillForSession($session);
            $session = TillSession::lockForUpdate()->findOrFail($session->id);

            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
                'SHIFT_NOT_OPEN',
                'Only an open or closing shift may be settled.'
            );

            $this->assertNoLivePendingPayments($session);

            // 1. Closing-phase denomination counts (overwrite drafts).
            TillCashDenominationCount::where('session_id', $session->id)
                ->where('count_phase', TillCountPhaseEnum::Closing->value)
                ->delete();
            $denomCash = $this->persistDenominationCounts(
                $session,
                $session->default_currency_code ?? 'JPY',
                TillCountPhaseEnum::Closing,
                $data['closing_counts']
            );
            // "Tiền lẻ / điều chỉnh": phần tiền mặt không biểu diễn được bằng
            // mệnh giá. Cộng vào counted cash để variance khớp thực tế.
            $cashAdjustment = round((float) ($data['closing_cash_adjustment'] ?? 0), 2);
            $countedCash = round($denomCash + $cashAdjustment, 2);

            // 2. Reconciliation snapshot (expected sides).
            $recon = $this->reconcile($session->fresh());

            // 3. Per-tender settlement details (one per active TillTenderType).
            $tolerance = (float) ($till->variance_tolerance_amount ?? 0);
            $varianceMissing = $this->persistSettlementDetails(
                $session,
                $data['tender_details'] ?? [],
                $recon['tenders'],
                $recon['category_expected'],
                $tolerance
            );

            // 4. Cash variance reason check.
            $expectedCash = (float) $recon['cash']['expected_cash'];
            $cashVariance = round($countedCash - $expectedCash, 2);
            $cashReasonMissing = abs($cashVariance) > $tolerance
                && empty(trim((string) ($data['closing_note'] ?? '')))
                && ! $this->cashReasonProvided($data['tender_details'] ?? []);

            if ($varianceMissing || $cashReasonMissing) {
                abort(response()->json([
                    'message' => 'A variance reason is required for at least one tender/cash row.',
                    'code' => 'VARIANCE_REASON_REQUIRED',
                    'tenders_missing_reason' => $varianceMissing,
                    'cash_missing_reason' => $cashReasonMissing,
                ], 422));
            }

            // 5. Stamp session (+ plan-046 settlement_kind + immutable snapshot).
            $session->update([
                'status' => TillSessionStatusEnum::Settled->value,
                'closed_at' => now(),
                'closed_by_id' => $data['closed_by_id'] ?? Auth::id(),
                'closing_note' => $data['closing_note'] ?? $session->closing_note,
                'opening_float_amount' => $session->opening_float_amount,
                'expected_cash_amount' => $expectedCash,
                'counted_cash_amount' => $countedCash,
                'closing_cash_adjustment_amount' => $cashAdjustment,
                'cash_variance_amount' => $cashVariance,
                // #552 — freeze recognized revenue so this shift's Z-report is
                // immutable from here on (reconcile() reads these back once set).
                ...$this->revenueSnapshotColumns($recon),
                // plan-046 (R2): settlement_kind + write-once snapshot the chain
                // aggregate sums. handover keeps the chain open, final ends it.
                'settlement_kind' => $kind->value,
                'settlement_snapshot' => $this->buildSettlementSnapshot($session, $recon, $countedCash, $cashVariance),
            ]);

            // 6. Clear till lock — guarded (like abandon/expire) so a stale
            //    write never nulls a pointer that references a different live
            //    shift on the same till (issue #817 defense-in-depth).
            if ($till->current_session_id === $session->id) {
                $till->update(['current_session_id' => null]);
            }

            // Audit: handover gets its own action; final keeps till_session_settled
            // (backward-compatible — the settlement_kind column is the discriminator).
            $auditAction = $kind === TillSettlementKindEnum::Handover
                ? 'till_session_handover'
                : 'till_session_settled';
            $session->logAudit($auditAction, [
                'session_code' => $session->session_code,
                'settlement_kind' => $kind->value,
                'chain_id' => $session->chain_id,
                'chain_sequence' => (int) $session->chain_sequence,
                'expected_cash' => $expectedCash,
                'counted_cash' => (float) $countedCash,
                'cash_variance' => $cashVariance,
            ]);

            return $this->findById($session->id);
        });
    }

    /**
     * Build the immutable per-shift settlement snapshot (plan-046 T2.5, R2).
     *
     * TWO sources (GAP-1): cash / tenders / revenue come from the passed
     * reconcile() result; the per-rate tax_breakdown comes from Ordering's
     * `OrderTaxBreakdownReads` port because reconcile() only yields a
     * single revenue.tax total, NOT the 8%/10% split the chain aggregate must
     * print per bucket (R4 / インボイス). Written once at settle and never mutated
     * — chainSummary() sums these frozen snapshots across a chain.
     *
     * #962 (7b) — cái nguồn thứ hai giờ đi qua cổng `OrderTaxBreakdownReads` do
     * Ordering công bố. Vẫn đúng phép cộng cũ: cổng chỉ chuyển tiếp.
     *
     * @param  array{revenue: array<string, float>, cash: array<string, float>, tenders: mixed}  $recon
     * @return array{opening_float: float, cash: array{expected: float, counted: float, variance: float, sales: float, paid_in: float, paid_out: float}, tenders: mixed, tax_breakdown: list<array{rate: float, taxable: float, tax: float}>, revenue: array{gross: float, net: float, tax: float, discount: float}, orders: array{paid_count: int, paid_total: float}}
     */
    private function buildSettlementSnapshot(TillSession $session, array $recon, float $countedCash, float $cashVariance): array
    {
        // Distinct orders whose payments are attributed to this shift (plan-044).
        $sessionOrderIds = OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->distinct()
            ->pluck('customer_order_id')
            ->filter()
            ->values();

        $taxAgg = app(OrderTaxBreakdownReads::class)->forOrders($sessionOrderIds);

        return [
            'opening_float' => (float) ($recon['cash']['opening_float'] ?? 0),
            'cash' => [
                'expected' => (float) ($recon['cash']['expected_cash'] ?? 0),
                'counted' => $countedCash,
                'variance' => $cashVariance,
                'sales' => (float) ($recon['cash']['cash_sales'] ?? 0),
                'paid_in' => (float) ($recon['cash']['paid_in'] ?? 0),
                'paid_out' => (float) ($recon['cash']['paid_out'] ?? 0),
            ],
            'tenders' => $recon['tenders'] ?? [],
            'tax_breakdown' => $taxAgg['by_rate'] ?? [],
            'revenue' => [
                'gross' => (float) ($recon['revenue']['gross'] ?? 0),
                'net' => (float) ($recon['revenue']['net'] ?? 0),
                'tax' => (float) ($recon['revenue']['tax'] ?? 0),
                'discount' => (float) ($recon['revenue']['discount'] ?? 0),
            ],
            'orders' => [
                'paid_count' => $sessionOrderIds->count(),
                'paid_total' => (float) ($recon['revenue']['gross'] ?? 0),
            ],
            ...$this->settlementSnapshotDetail($session, $sessionOrderIds),
        ];
    }

    /**
     * plan-046 step 2 — the sections the chain aggregate cannot reconstruct from
     * the money keys alone: 点数 / 人, named 割引, 伝票削除, 入出金件数 and 金種.
     *
     * The key names and value types here MUST stay byte-identical to the
     * workstation's buildLocalSettlementSnapshot: this snapshot OVERWRITES the
     * workstation's provisional one through the sync-UP response (R7), so any
     * drift would make sections silently disappear from the chain slip the
     * moment the queue drains.
     *
     * @param  SupportCollection<int, string>  $sessionOrderIds
     * @return array{counts: array{item_count: int, guest_count: int}, discounts: list<array{label: string, count: int, amount: float}>, voids: array{unpaid_count: int, unpaid_amount: float, paid_count: int, paid_amount: float}, cash_events: array{paid_in_count: int, paid_out_count: int}, denominations: list<array{value: float, quantity: int, subtotal: float}>}
     */
    private function settlementSnapshotDetail(TillSession $session, SupportCollection $sessionOrderIds): array
    {
        $orderIds = array_map('strval', $sessionOrderIds instanceof SupportCollection
            ? $sessionOrderIds->all() : (array) $sessionOrderIds);

        // #962 — 点数 và 人 in cạnh nhau nhưng đọc hai bảng của Ordering; cả hai
        // giờ qua cùng một cổng. Luật "bỏ dòng đã void" ở lại phía sở hữu bảng.
        $itemCount = $this->orders->itemQuantityForOrders($orderIds);

        $guestCount = $this->orders->guestCountForOrders($orderIds);

        // 支払方法 — the ACTUAL payment-method split with transaction counts.
        // `tenders` cannot serve this: it is keyed by till TENDER TYPE with an
        // expected amount and carries no count, so a chain slip built from it
        // could not reproduce the per-shift slip's payment section.
        // Mirror the authoritative per-method reconcile ($paymentSums below): count
        // sale originals that entered the drawer (succeeded OR refunded — a later
        // cross-shift refund must not retroactively erase this shift's take) plus
        // this shift's refund rows (always succeeded). `pending` never hit the
        // drawer and must be excluded, or the split overcounts vs the gross printed
        // beside it. (`confirmed` is a CustomerOrder status, never a payment status.)
        $payments = OrderPayment::query()
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'order_payments.payment_method_id')
            ->where('order_payments.till_session_id', $session->id)
            ->where(function ($q) {
                $q->where(function ($sale) {
                    $sale->whereNull('order_payments.refund_of_id')
                        ->whereIn('order_payments.status', [
                            PaymentStatusEnum::Succeeded->value,
                            PaymentStatusEnum::Refunded->value,
                        ]);
                })->orWhere(function ($refund) {
                    $refund->whereNotNull('order_payments.refund_of_id')
                        ->where('order_payments.status', PaymentStatusEnum::Succeeded->value);
                });
            })
            ->groupBy('payment_methods.code', 'payment_methods.name')
            ->selectRaw("COALESCE(payment_methods.code, 'unknown') as code, COALESCE(payment_methods.name, '') as label, COUNT(*) as cnt, COALESCE(SUM(order_payments.amount), 0) as amount")
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'code' => (string) $r->code,
                'label' => (string) ($r->label !== '' ? $r->label : $r->code),
                'count' => (int) $r->cnt,
                'amount' => (float) $r->amount,
            ])
            ->values()
            ->all();

        // Cloud stores redemptions with a coupon FK; the workstation mirror keeps
        // the code on the row. Join for the code so both emit the same `label`.
        //
        // #962 — cạnh `Payments → Pricing` (`CouponRedemption`) CỐ Ý Ở LẠI, và
        // đây là chỗ DUY NHẤT phát ra nó. Nó là một câu tổng hợp cho Z-report:
        // gộp theo `coupons.code`, cộng `discount_applied_amount`, lọc
        // `released_at IS NULL` — tức nó cần JOIN và GROUP BY trên bảng của
        // Pricing, không cần một dòng redemption nào. Một cổng đọc trả về danh
        // sách redemption rồi cộng trong PHP sẽ biến một câu SQL thành N dòng
        // nạp vào bộ nhớ, bên trong đường kết ca vốn đã nặng — đổi một phép đo
        // đẹp hơn lấy một trang Z-report chậm hơn.
        //
        // Cạnh này đi khi Pricing công bố một cổng TỔNG HỢP (trả về đúng
        // `[label, count, amount]` đã gộp sẵn), không phải một cổng liệt kê.
        $discounts = CouponRedemption::query()
            ->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->whereIn('coupon_redemptions.customer_order_id', $sessionOrderIds)
            ->whereNull('coupon_redemptions.released_at')
            ->groupBy('coupons.code')
            ->selectRaw('coupons.code as label, COUNT(*) as cnt, COALESCE(SUM(coupon_redemptions.discount_applied_amount), 0) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'label' => (string) $r->label,
                'count' => (int) $r->cnt,
                'amount' => (float) $r->amount,
            ])
            ->values()
            ->all();

        // 伝票削除 — a voided order carries no payment, so it can never be
        // attributed to a shift; the open→close window is the only signal there
        // is, and it is the correct one here.
        $voidUnpaidCount = 0;
        $voidUnpaidAmount = 0.0;
        $voidPaidCount = 0;
        $voidPaidAmount = 0.0;
        // #1647 — như trên: Ordering trả đơn VOID trong cửa sổ ca, Payments tự
        // đếm payment còn sống của chúng. Tập này thường bằng 0.
        $voided = $this->orders->voidedForBranchBetween(
            (string) $session->branch_id,
            $session->opened_at ? (string) $session->opened_at : null,
            (string) ($session->closed_at ?? now()),
        );

        $withLivePayment = $voided === [] ? [] : OrderPayment::query()
            ->whereIn('customer_order_id', array_map(fn ($o) => $o->orderId, $voided))
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->distinct()
            ->pluck('customer_order_id')
            ->all();
        $withLivePayment = array_flip(array_map('strval', $withLivePayment));

        foreach ($voided as $o) {
            if (isset($withLivePayment[$o->orderId])) {
                $voidPaidCount++;
                $voidPaidAmount += $o->totalAmount;

                continue;
            }
            $voidUnpaidCount++;
            $voidUnpaidAmount += $o->totalAmount;
        }

        $eventCounts = TillCashEvent::query()
            ->where('session_id', $session->id)
            ->groupBy('event_type')
            ->selectRaw('event_type, COUNT(*) as cnt')
            ->pluck('cnt', 'event_type');
        $paidInCount = (int) ($eventCounts['paid_in'] ?? 0) + (int) ($eventCounts['loan_from_safe'] ?? 0);
        $paidOutCount = (int) ($eventCounts['paid_out'] ?? 0) + (int) ($eventCounts['pickup_to_safe'] ?? 0);

        // The count row denormalizes `denomination_value`, so no join is needed
        // (and the column is `count_phase`, not `phase`).
        $denominations = TillCashDenominationCount::query()
            ->where('session_id', $session->id)
            ->where('count_phase', TillCountPhaseEnum::Closing->value)
            ->orderByDesc('denomination_value')
            ->get([
                'denomination_value as value',
                'quantity',
                'subtotal_amount as subtotal',
            ])
            ->map(fn ($r) => [
                'value' => (float) $r->value,
                'quantity' => (int) $r->quantity,
                'subtotal' => (float) $r->subtotal,
            ])
            ->values()
            ->all();

        return [
            'counts' => [
                'item_count' => $itemCount,
                'guest_count' => $guestCount,
            ],
            'payments' => $payments,
            'discounts' => $discounts,
            'voids' => [
                'unpaid_count' => $voidUnpaidCount,
                'unpaid_amount' => $voidUnpaidAmount,
                'paid_count' => $voidPaidCount,
                'paid_amount' => $voidPaidAmount,
            ],
            'cash_events' => [
                'paid_in_count' => $paidInCount,
                'paid_out_count' => $paidOutCount,
            ],
            'denominations' => $denominations,
        ];
    }

    /**
     * Plan-046 (T2.4) — aggregate chain summary. Loads a chain's SETTLED members
     * (handover + final) and sums each immutable settlement_snapshot into per-shift
     * blocks + a grand total. Never re-derives from live orders (R2/R4: a later
     * refund of an earlier shift's order can't retro-change a settled block).
     *
     * Branch-scoped (404 cross-branch, mirrors the controller's shop_id guard).
     * G1: abandoned/expired mid-chain shifts keep the chain_id but a NULL snapshot
     * (R5) — the settlement_kind filter excludes them so the Σ never null-derefs.
     *
     * @return array{chain_id: string, branch_id: string, till_code: ?string, opened_at: mixed, closed_at: mixed, chain_open: bool, shifts: list<array<string, mixed>>, grand_total: array{cash: array<string, float>, tax_breakdown: list<array<string, float>>, revenue: array<string, float>}}
     */
    public function chainSummary(string $chainId, string $branchId): array
    {
        /** @var SupportCollection<int, TillSession> $members */
        $members = TillSession::query()
            ->with('till:id,till_code')
            ->where('chain_id', $chainId)
            ->where('branch_id', $branchId)
            ->whereIn('settlement_kind', [
                TillSettlementKindEnum::Handover->value,
                TillSettlementKindEnum::Final->value,
            ])
            ->get()
            // P5-5: chain_sequence is a VARCHAR column (Omnify Integer quirk) —
            // sort numerically in PHP, never a lexical DB order ('10' < '2').
            ->sortBy(fn (TillSession $s) => (int) $s->chain_sequence)
            ->values();

        if ($members->isEmpty()) {
            abort(response()->json([
                'message' => 'Chain not found for this branch.',
                'code' => 'CHAIN_NOT_FOUND',
            ], 404));
        }

        $shifts = $members->map(function (TillSession $s): array {
            $snap = is_array($s->settlement_snapshot) ? $s->settlement_snapshot : [];

            return [
                'session_code' => $s->session_code,
                'chain_sequence' => (int) $s->chain_sequence,
                'settlement_kind' => $s->settlement_kind?->value,
                'opener_name' => $s->opener_name,
                'opened_at' => $s->opened_at,
                'closed_at' => $s->closed_at,
                'cash' => $snap['cash'] ?? [],
                'tax_breakdown' => $snap['tax_breakdown'] ?? [],
                'revenue' => $snap['revenue'] ?? [],
                // plan-046 step 2 — absent on a chain settled before the
                // enrichment; consumers must treat missing as "not recorded"
                // and omit the section rather than render a confident zero.
                'counts' => $snap['counts'] ?? null,
                'payments' => $snap['payments'] ?? null,
                'discounts' => $snap['discounts'] ?? null,
                'voids' => $snap['voids'] ?? null,
                'cash_events' => $snap['cash_events'] ?? null,
                'denominations' => $snap['denominations'] ?? null,
            ];
        })->values();

        $last = $members->last();

        return [
            'chain_id' => $chainId,
            'branch_id' => $branchId,
            'till_code' => $members->first()->till?->till_code,
            'opened_at' => $members->first()->opened_at,
            'closed_at' => $last->closed_at,
            // chain_open: the max-sequence member is a handover (not final), so the
            // chain is still running — preview only; the final print needs a final.
            'chain_open' => $last->settlement_kind !== TillSettlementKindEnum::Final,
            'shifts' => $shifts->all(),
            'grand_total' => $this->sumChainSnapshots($members),
        ];
    }

    /**
     * Sum a chain's per-shift snapshots into a grand total (plan-046 R4). Cash and
     * revenue add straight; per-rate tax is summed PER BUCKET (8% with 8%, never
     * merged). The total is Σ(already-rounded per-shift figures) — each shift was
     * rounded once at its own settle, so the chain does NOT re-round.
     *
     * @param  SupportCollection<int, TillSession>  $members
     * @return array{cash: array<string, float>, tax_breakdown: list<array<string, float>>, revenue: array<string, float>}
     */
    private function sumChainSnapshots($members): array
    {
        $cash = ['expected' => 0.0, 'counted' => 0.0, 'variance' => 0.0, 'sales' => 0.0, 'paid_in' => 0.0, 'paid_out' => 0.0];
        $revenue = ['gross' => 0.0, 'net' => 0.0, 'tax' => 0.0, 'discount' => 0.0];
        $counts = ['item_count' => 0, 'guest_count' => 0];
        $voids = ['unpaid_count' => 0, 'unpaid_amount' => 0.0, 'paid_count' => 0, 'paid_amount' => 0.0];
        $cashEvents = ['paid_in_count' => 0, 'paid_out_count' => 0];
        $byRate = [];
        $byPayment = [];
        $byDiscount = [];
        $byDenom = [];
        $sawDetail = false;

        foreach ($members as $s) {
            $snap = is_array($s->settlement_snapshot) ? $s->settlement_snapshot : [];
            foreach (array_keys($cash) as $k) {
                $cash[$k] += (float) ($snap['cash'][$k] ?? 0);
            }
            foreach (array_keys($revenue) as $k) {
                $revenue[$k] += (float) ($snap['revenue'][$k] ?? 0);
            }
            foreach (($snap['tax_breakdown'] ?? []) as $bucket) {
                $rateKey = (string) ((float) ($bucket['rate'] ?? 0));
                if (! isset($byRate[$rateKey])) {
                    $byRate[$rateKey] = ['rate' => (float) ($bucket['rate'] ?? 0), 'taxable' => 0.0, 'tax' => 0.0];
                }
                $byRate[$rateKey]['taxable'] += (float) ($bucket['taxable'] ?? 0);
                $byRate[$rateKey]['tax'] += (float) ($bucket['tax'] ?? 0);
            }

            // plan-046 step 2 sections. A pre-enrichment member simply
            // contributes nothing; `$sawDetail` records whether ANY member
            // carried them so the caller can tell "all zero" from "never
            // recorded" and omit the section instead of printing zeros.
            if (isset($snap['counts'])) {
                $sawDetail = true;
                $counts['item_count'] += (int) ($snap['counts']['item_count'] ?? 0);
                $counts['guest_count'] += (int) ($snap['counts']['guest_count'] ?? 0);
            }
            if (isset($snap['voids'])) {
                $sawDetail = true;
                foreach (array_keys($voids) as $k) {
                    $voids[$k] += str_ends_with($k, '_count')
                        ? (int) ($snap['voids'][$k] ?? 0)
                        : (float) ($snap['voids'][$k] ?? 0);
                }
            }
            if (isset($snap['cash_events'])) {
                $sawDetail = true;
                foreach (array_keys($cashEvents) as $k) {
                    $cashEvents[$k] += (int) ($snap['cash_events'][$k] ?? 0);
                }
            }
            foreach (($snap['payments'] ?? []) as $p) {
                $sawDetail = true;
                $code = (string) ($p['code'] ?? '');
                if (! isset($byPayment[$code])) {
                    $byPayment[$code] = ['code' => $code, 'label' => (string) ($p['label'] ?? $code), 'count' => 0, 'amount' => 0.0];
                }
                $byPayment[$code]['count'] += (int) ($p['count'] ?? 0);
                $byPayment[$code]['amount'] += (float) ($p['amount'] ?? 0);
            }
            foreach (($snap['discounts'] ?? []) as $d) {
                $sawDetail = true;
                $label = (string) ($d['label'] ?? '');
                if (! isset($byDiscount[$label])) {
                    $byDiscount[$label] = ['label' => $label, 'count' => 0, 'amount' => 0.0];
                }
                $byDiscount[$label]['count'] += (int) ($d['count'] ?? 0);
                $byDiscount[$label]['amount'] += (float) ($d['amount'] ?? 0);
            }
            // Denominations sum per FACE VALUE — the physical note/coin mix each
            // cashier counted, added up across the chain.
            foreach (($snap['denominations'] ?? []) as $dn) {
                $sawDetail = true;
                $valKey = (string) ((float) ($dn['value'] ?? 0));
                if (! isset($byDenom[$valKey])) {
                    $byDenom[$valKey] = ['value' => (float) ($dn['value'] ?? 0), 'quantity' => 0, 'subtotal' => 0.0];
                }
                $byDenom[$valKey]['quantity'] += (int) ($dn['quantity'] ?? 0);
                $byDenom[$valKey]['subtotal'] += (float) ($dn['subtotal'] ?? 0);
            }
        }

        ksort($byRate, SORT_NUMERIC); // deterministic per-rate order
        krsort($byDenom, SORT_NUMERIC); // largest note first, as counted
        uasort($byDiscount, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        uasort($byPayment, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'cash' => $cash,
            'tax_breakdown' => array_values($byRate),
            'revenue' => $revenue,
            'counts' => $sawDetail ? $counts : null,
            'payments' => $sawDetail ? array_values($byPayment) : null,
            'discounts' => $sawDetail ? array_values($byDiscount) : null,
            'voids' => $sawDetail ? $voids : null,
            'cash_events' => $sawDetail ? $cashEvents : null,
            'denominations' => $sawDetail ? array_values($byDenom) : null,
        ];
    }

    /**
     * Chốt ca đến từ workstation sync-UP (offline replay). Issue #817.
     *
     * Cloud-authoritative variant of close(). The workstation used to ship a
     * pre-computed counted_cash + cash_variance and Cloud fabricated
     * expected = counted − variance, bypassing reconcile() and every guard the
     * cashier-driven close() enforces — so a device-declared "variance = 0"
     * produced a clean Z-report no matter how much cash was missing. Here Cloud
     * computes expected via reconcile() (from payments synced with
     * till_session_id) and variance itself; the device's cash_variance is
     * ignored.
     *
     * Deliberately differs from close() for offline-replay safety:
     *   - Idempotent: a replayed sync item on an already-`settled` session is a
     *     no-op read-back (never rewrites a chốt Z-report). A different terminal
     *     status (abandoned/expired) is a 409.
     *   - No hard VARIANCE_REASON_REQUIRED abort — an offline close already
     *     happened, so the cashier can't retroactively supply a reason;
     *     blocking would dead-letter the sync item and orphan the till (the very
     *     failure #817 fights). Phase A settles with the Cloud-computed variance
     *     and logs it for manager triage; Phase B adds enforcement once the
     *     workstation captures the reason pre-close.
     *   - counted_cash stays device-owned (Cloud can't count a physical
     *     drawer): the device's declared figure is kept, with a SIGNED
     *     adjustment vs the denomination sum (a count below the sum surfaces a
     *     negative adjustment — never clamped, else a shortage is erased).
     *   - closed_at / closed_by_id ride in from the payload (offline clock +
     *     cashier user id), not now()/Auth::id().
     *   - Unknown tender_keys / negative figures are skipped+normalised, never a
     *     422 (see sanitizeWorkstationTenderDetails).
     *
     * Workstation-authoritative shift OPEN. Accepts the device-supplied id +
     * timestamps + chain grouping, applies the idempotent-replay / one-open-shift
     * / currency-mismatch guards, derives the opening float from the counted
     * denominations (#817), and points the till at the new session. Extracted
     * verbatim from Api/V1/Workstation/TillController::openSession (plan-047).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: TillSession, 1: bool} [session, created] — created=false on an idempotent retry
     */
    public function openFromWorkstation(string $branchId, array $data): array
    {
        // Idempotent: a retry returns the existing row.
        if ($existing = TillSession::find($data['id'])) {
            $existing->load(['cashEvents', 'openingCounts.denomination']);

            return [$existing, false];
        }

        $till = $this->resolveTillForBranch($branchId);

        // Refuse to plant the new session as `current` if the till already has
        // another open shift — a real conflict (workstation reconnected after
        // running its own offline shift while staff also opened one on Cloud).
        // Caller must reconcile manually.
        if ($till->current_session_id !== null && $till->current_session_id !== $data['id']) {
            abort(response()->json([
                'message' => 'Till has a different open shift on Cloud. Manual reconcile required.',
                'code' => 'SHIFT_ALREADY_OPEN',
                'cloud_session_id' => $till->current_session_id,
            ], 409));
        }

        // Plan-046 — store the device's chain_id/chain_sequence VERBATIM
        // (workstation-authoritative). For an old client that omits them, fall
        // back to the Cloud resolver (same logic as pos open()).
        $chainId = $data['chain_id'] ?? null;
        $chainSequence = $data['chain_sequence'] ?? null;
        if ($chainId === null) {
            $prev = $this->previousTerminalSessionForTill($till);
            if ($prev !== null && $prev['session']->settlement_kind === TillSettlementKindEnum::Handover) {
                $chainId = $prev['session']->chain_id ?: (string) Str::uuid();
                $chainSequence = (int) $prev['session']->chain_sequence + 1;
            } else {
                $chainId = (string) Str::uuid();
                $chainSequence = 1;
            }
        }

        $session = DB::transaction(function () use ($till, $data, $chainId, $chainSequence) {
            // Normalize the client-supplied timestamp to the app timezone before
            // storing. The workstation sends UTC ("…Z"); with APP_TIMEZONE set to
            // a non-UTC zone (Asia/Tokyo), Eloquent stores a Carbon formatted in
            // the Carbon's OWN tz but READS it back in the app tz — so a raw UTC
            // value round-trips wrong. Casting to the app tz first keeps store +
            // read consistent so the serialized instant is correct.
            $openedAt = Carbon::parse($data['opened_at'])->setTimezone(config('app.timezone', 'UTC'));

            // #1026 — the workstation mints a temporary `WS-<ts>-<hex>` code
            // offline (it cannot reach the global sequence). Re-mint the
            // canonical `SHIFT-YYYYMMDD-NNN` here so Cloud UI shows one code
            // scheme (mirrors the order plan-041 re-mint); the workstation
            // adopts the new code from this response. Idempotency is keyed on
            // the client uuid, so a retried open returns the already re-minted
            // row. The original WS- code is logged for offline trace.
            $sessionCode = (string) $data['session_code'];
            if (str_starts_with($sessionCode, 'WS-')) {
                Log::info('till_session_code_reminted', [
                    'session_id' => $data['id'],
                    'from' => $sessionCode,
                    'branch_id' => (string) $till->branch_id,
                ]);
                $sessionCode = $this->generateSessionCode($openedAt);
            }

            // TillSession uses HasUuids, which generates a UUIDv7 on `creating`
            // ONLY when the id column is empty. `id` is not in $fillable, so a
            // plain create(['id' => …]) silently drops the workstation-supplied id
            // and Cloud picks its own — the workstation could then never look up
            // the cloud row by its own id, breaking idempotency for retried opens.
            // Set id BEFORE save() so HasUuids sees it populated and skips.
            $session = new TillSession([
                'session_code' => $sessionCode,
                'status' => TillSessionStatusEnum::Open->value,
                // #1091 — the workstation-supplied open instant is converted to
                // the BRANCH's calendar day (an offline open at 00:30 JST must
                // land on the JST date, not the UTC one).
                'business_date' => BusinessClock::businessDateAt((string) $till->branch_id, $openedAt),
                'default_currency_code' => strtoupper($data['currency_code']),
                // Derived from opening_counts after save() below (#817).
                'opening_float_amount' => 0,
                'opening_note' => $data['opening_note'] ?? null,
                'opened_by_id' => $data['opened_by_id'] ?? null,
                'opener_name' => $data['opener_name'] ?? null,
                'opened_at' => $openedAt,
                'till_id' => $till->id,
                'branch_id' => $till->branch_id,
                'brand_id' => $till->brand_id,
                'organization_id' => $till->organization_id,
                // plan-046 chain grouping (workstation-authoritative).
                'chain_id' => $chainId,
                'chain_sequence' => $chainSequence ?? 1,
            ]);
            $session->id = $data['id'];
            $session->save();

            $currency = strtoupper($data['currency_code']);
            $float = 0.0;
            foreach ($data['opening_counts'] as $c) {
                /** @var Denomination|null $denom */
                $denom = Denomination::find($c['denomination_id']);
                // #817: enforce the same DENOMINATION_CURRENCY_MISMATCH guard the
                // pos path applies, and DERIVE the opening float from the counted
                // denominations instead of trusting the client-supplied
                // opening_float_amount (which bypassed the guard entirely).
                if ($denom) {
                    $this->assertDenominationCurrency($denom, $currency);
                }
                // Schema's actual column is `count_phase` (NOT `phase`). All five
                // referenced columns are NOT NULL — mirror the field set open()
                // writes so workstation-originated shifts land on Cloud.
                $denomValue = $denom ? (float) $denom->value : 0;
                $denomKind = $denom?->kind instanceof \BackedEnum
                    ? $denom->kind->value
                    : ($denom?->kind ?? 'note');
                $subtotal = round($denomValue * (int) $c['quantity'], 2);
                TillCashDenominationCount::create([
                    'session_id' => $session->id,
                    'denomination_id' => $c['denomination_id'],
                    'count_phase' => TillCountPhaseEnum::Opening->value,
                    'quantity' => $c['quantity'],
                    'subtotal_amount' => $subtotal,
                    'currency_code' => $denom?->currency_code ?? $currency,
                    'denomination_value' => $denomValue,
                    'denomination_kind' => $denomKind,
                ]);
                $float += $subtotal;
            }

            $session->update(['opening_float_amount' => round($float, 2)]);

            $till->update(['current_session_id' => $session->id]);

            return $session;
        });

        $session->load(['cashEvents', 'openingCounts.denomination']);

        // #2696 — LAN-first shops mở ca qua workstation, không qua `open()`.
        // Không gọi trên retry idempotent: ca đã mở rồi, chuông đã kêu (hoặc
        // đã fail-open) ở lượt tạo thật.
        $this->notifyUnresolvedOrdersAtOpen($session);

        return [$session, true];
    }

    /**
     * Workstation-authoritative 入金/出金 giữa ca. Extracted verbatim from
     * Api/V1/Workstation/TillController::cashEvent (#1695).
     *
     * Deliberately NOT `recordCashEvent()` above: this is the sync-UP twin, and
     * the two differ on every axis that matters here.
     *
     *   - **The id comes from the DEVICE.** TillCashEvent uses HasUuids, which
     *     only mints a UUIDv7 when `id` is still empty, and `id` is NOT in
     *     $fillable — so `create(['id' => …])` silently DROPS it and Cloud picks
     *     its own. The device-supplied id IS the idempotency key of the sync
     *     queue: without it every retry of a flaky POST lands ANOTHER cash row
     *     (money doubled in the 過不足 reconcile) and the workstation can never
     *     confirm the event was persisted. Hence `new` + assign `id` + `save()`.
     *   - **The clock comes from the DEVICE.** `occurred_at` is the workstation's
     *     wall clock, shipped as UTC ("…Z"). Under a non-UTC APP_TIMEZONE
     *     Eloquent WRITES a Carbon in the Carbon's own tz but READS it back in
     *     the app tz, so a raw UTC value round-trips shifted by the offset (#1091
     *     — the same normalisation openFromWorkstation applies to `opened_at`).
     *   - No `Auth::id()` fallback for `performed_by_id` (device token, no user)
     *     and no open-status assertion: an offline event replayed after the shift
     *     already closed must still land, not 409 into a dead-letter.
     *
     * @param  array{
     *     id: string,
     *     event_type: string,
     *     amount: float|int|string,
     *     currency_code?: ?string,
     *     reason?: ?string,
     *     reference_no?: ?string,
     *     performed_by_id?: ?string,
     *     occurred_at: string,
     *   }  $data
     * @return array{0: TillCashEvent, 1: bool} [event, created] — created=false on an idempotent retry
     */
    public function recordCashEventFromWorkstation(TillSession $session, array $data): array
    {
        // Idempotent: a retry returns the existing row untouched (never a second
        // cash movement, never an overwrite of the one already reconciled).
        if ($existing = TillCashEvent::find($data['id'])) {
            return [$existing, false];
        }

        $event = new TillCashEvent([
            'session_id' => $session->id,
            'event_type' => $data['event_type'],
            'amount' => $data['amount'],
            'currency_code' => strtoupper($data['currency_code'] ?? $session->default_currency_code ?? 'JPY'),
            'reason' => $data['reason'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'performed_by_id' => $data['performed_by_id'] ?? null,
            'occurred_at' => Carbon::parse($data['occurred_at'])->setTimezone(config('app.timezone', 'UTC')),
        ]);
        $event->id = $data['id'];
        $event->save();

        // #1704 — đường POS ghi vết cho đúng thao tác này (`recordCashEvent`),
        // đường máy trạm thì không. Mà máy trạm chính là nơi PHẦN LỚN tiền mặt
        // đi qua ở cửa hàng chạy POS offline: khi 過不足 lệch, câu hỏi đầu tiên
        // là ai bỏ tiền vào, lúc nào, vì lý do gì — và trước bản này nó không
        // trả lời được.
        //
        // HAI mốc thời gian, cố ý. Một sự kiện replay từ offline mang mốc CŨ:
        // `occurred_at` là lúc tiền thật sự vào/ra ngăn kéo (giờ tường của máy
        // trạm, đã chuẩn hoá), còn dấu thời gian của chính dòng audit là lúc
        // Cloud nhận được. Ghi một cái rồi bỏ cái kia là làm bản kiểm nói dối
        // theo một trong hai hướng — hoặc mất dấu tiền cầm cả đêm mới sync,
        // hoặc mất dấu độ trễ sync.
        //
        // Nằm SAU `$existing` early-return nên replay idempotent không sinh
        // dòng audit thứ hai.
        $session->logAudit('till_cash_event_recorded', [
            'event_type' => $event->event_type instanceof TillCashEventTypeEnum
                ? $event->event_type->value
                : $event->event_type,
            'amount' => (float) $event->amount,
            'source' => 'workstation',
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'cash_event_id' => $event->id,
        ]);

        return [$event, true];
    }

    /**
     * Workstation-authoritative shift ABANDON. Idempotent replay on an
     * already-abandoned shift returns the frozen row; a shift already in another
     * terminal state (settled/expired) is refused with 409. Releases the till
     * only if it still points at THIS session. Extracted verbatim from
     * Api/V1/Workstation/TillController::abandon (plan-047).
     *
     * @param  array<string, mixed>  $data
     */
    public function abandonFromWorkstation(TillSession $session, array $data): TillSession
    {
        $status = $session->status instanceof \BackedEnum
            ? $session->status->value
            : $session->status;

        // Idempotent replay: a retried abandon on an already-abandoned shift
        // returns the frozen row (#817).
        if ($status === TillSessionStatusEnum::Abandoned->value) {
            return $session;
        }
        // Never abandon a shift that already reached a different terminal state —
        // a stale replay must not overwrite a settled/expired Z-report or release
        // a till it no longer owns.
        if (in_array($status, [
            TillSessionStatusEnum::Settled->value,
            TillSessionStatusEnum::Expired->value,
        ], true)) {
            abort(response()->json([
                'message' => 'Shift already reached a terminal status; cannot abandon.',
                'code' => 'SHIFT_TERMINAL_STATE',
                'status' => $status,
            ], 409));
        }

        // Abandon uses `abandoned_at` (closed_at stays NULL for the ABANDONED
        // status — closed_at is settled-only). The Manager Till Tracking
        // dashboard reads `abandoned_at` for the force-abandon activity card.
        DB::transaction(function () use ($session, $data) {
            $session->update([
                'status' => TillSessionStatusEnum::Abandoned->value,
                'abandoned_at' => Carbon::parse($data['closed_at'])->setTimezone(config('app.timezone', 'UTC')),
                'abandon_reason' => $data['abandon_reason'] ?? null,
            ]);
            // Guard: only release the till if it still points at THIS session — a
            // stale replay must not orphan a newer shift on the same till.
            $till = $session->till_id
                ? Till::whereKey($session->till_id)->lockForUpdate()->first()
                : null;
            if ($till && $till->current_session_id === $session->id) {
                $till->update(['current_session_id' => null]);
            }
        });

        return $session->refresh();
    }

    /**
     * @param  array{
     *     closing_counts?: list<array{denomination_id: string, quantity: int}>,
     *     tender_details?: list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>,
     *     closing_note?: ?string,
     *     counted_cash?: float|int|string|null,
     *     closed_at?: string|\DateTimeInterface|null,
     *     closed_by_id?: ?string,
     *   }  $data
     */
    public function settleFromWorkstation(TillSession $session, array $data): TillSession
    {
        return DB::transaction(function () use ($session, $data) {
            $till = $this->lockTillForSession($session);
            $session = TillSession::lockForUpdate()->findOrFail($session->id);

            $status = $session->status instanceof TillSessionStatusEnum
                ? $session->status
                : TillSessionStatusEnum::from($session->status);

            // Idempotent replay: a retried sync item on an already-settled shift
            // returns the frozen row untouched — never rewrite a closed Z-report.
            if ($status === TillSessionStatusEnum::Settled) {
                return $this->findById($session->id);
            }
            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
                'SHIFT_NOT_OPEN',
                'Only an open or closing shift may be settled.'
            );

            $this->assertNoLivePendingPayments($session);

            $currency = $session->default_currency_code ?? 'JPY';

            // 1. Closing-phase denomination counts (overwrite drafts). Applies
            //    the currency-mismatch guard via persistDenominationCounts.
            TillCashDenominationCount::where('session_id', $session->id)
                ->where('count_phase', TillCountPhaseEnum::Closing->value)
                ->delete();
            $denomCash = $this->persistDenominationCounts(
                $session,
                $currency,
                TillCountPhaseEnum::Closing,
                $data['closing_counts'] ?? []
            );

            // counted_cash is device-owned. Keep the device's declared physical
            // count; the SIGNED adjustment records the non-denominable remainder
            // (a declared count below the denomination sum → negative → surfaces
            // the shortage instead of erasing it). Falls back to the denom sum
            // when the device sends no scalar.
            $declaredCounted = array_key_exists('counted_cash', $data) && $data['counted_cash'] !== null
                ? round((float) $data['counted_cash'], 2)
                : $denomCash;
            $countedCash = $declaredCounted;
            $cashAdjustment = round($declaredCounted - $denomCash, 2);

            // 2. Reconciliation snapshot — Cloud-authoritative expected side.
            $recon = $this->reconcile($session->fresh());
            $expectedCash = (float) $recon['cash']['expected_cash'];
            $cashVariance = round($countedCash - $expectedCash, 2);

            // 3. Per-tender settlement details. Sanitise first so an offline
            //    replay carrying a stale/invented tender_key or a negative never
            //    dead-letters (persistSettlementDetails aborts 422 on those).
            $tolerance = (float) ($till->variance_tolerance_amount ?? 0);
            $tenderInputs = $this->sanitizeWorkstationTenderDetails(
                $session,
                $data['tender_details'] ?? []
            );
            $tendersReasonMissing = $this->persistSettlementDetails(
                $session,
                $tenderInputs,
                $recon['tenders'],
                $recon['category_expected'],
                $tolerance
            );

            // 4. Cash variance reason — computed for observability, NOT enforced
            //    on this path (Phase A). Mirrors close()'s reason predicate.
            $cashReasonMissing = abs($cashVariance) > $tolerance
                && empty(trim((string) ($data['closing_note'] ?? '')))
                && ! $this->cashReasonProvided($tenderInputs);

            // 5. Stamp session with Cloud-computed figures + offline clock/cashier.
            $closedAt = isset($data['closed_at'])
                ? Carbon::parse($data['closed_at'])->setTimezone(config('app.timezone', 'UTC'))
                : now();
            // Plan-046 (T2.6): this is also the handover sync-UP accept path — the
            // workstation POSTs to /close with settlement_kind in the body (there is
            // no separate Cloud handover route). Default to final for an old-client
            // close that omits it. chain_id/chain_sequence are stored VERBATIM
            // (workstation-authoritative for grouping); settlement_snapshot is
            // RECOMPUTED authoritatively here (ignore the device's provisional) and
            // rides back to the workstation via shape() → adopt-if-present (R7).
            $kind = TillSettlementKindEnum::tryFrom((string) ($data['settlement_kind'] ?? ''))
                ?? TillSettlementKindEnum::Final;
            $session->update([
                'status' => TillSessionStatusEnum::Settled->value,
                'closed_at' => $closedAt,
                'closed_by_id' => $data['closed_by_id'] ?? null,
                'closing_note' => $data['closing_note'] ?? $session->closing_note,
                'expected_cash_amount' => $expectedCash,
                'counted_cash_amount' => $countedCash,
                'closing_cash_adjustment_amount' => $cashAdjustment,
                'cash_variance_amount' => $cashVariance,
                // #552 — freeze recognized revenue (same as cashier close()).
                ...$this->revenueSnapshotColumns($recon),
                // plan-046 chain fields (R2/R7).
                'chain_id' => $data['chain_id'] ?? $session->chain_id,
                'chain_sequence' => $data['chain_sequence'] ?? $session->chain_sequence,
                'settlement_kind' => $kind->value,
                'settlement_snapshot' => $this->buildSettlementSnapshot($session, $recon, $countedCash, $cashVariance),
            ]);

            // 6. Release the till — guarded so a stale replay never nulls a
            //    pointer that now references a different live shift.
            if ($till->current_session_id === $session->id) {
                $till->update(['current_session_id' => null]);
            }

            $auditAction = $kind === TillSettlementKindEnum::Handover
                ? 'till_session_handover'
                : 'till_session_settled';
            $session->logAudit($auditAction, [
                'session_code' => $session->session_code,
                'source' => 'workstation',
                'settlement_kind' => $kind->value,
                'chain_id' => $session->chain_id,
                'chain_sequence' => (int) $session->chain_sequence,
                'expected_cash' => $expectedCash,
                'counted_cash' => (float) $countedCash,
                'cash_variance' => $cashVariance,
            ]);

            // Phase A exposes (does not block) an unreasoned variance so managers
            // can triage via the dashboard short/over surfacing + this audit row.
            if ($tendersReasonMissing || $cashReasonMissing) {
                $session->logAudit('till_session_settled_variance_unreviewed', [
                    'session_code' => $session->session_code,
                    'cash_variance' => $cashVariance,
                    'tolerance' => $tolerance,
                    'cash_reason_missing' => $cashReasonMissing,
                    'tenders_missing_reason' => $tendersReasonMissing,
                ]);
            }

            return $this->findById($session->id);
        });
    }

    /**
     * Filter workstation-supplied tender rows to keys that exist as active
     * TillTenderTypes for the branch, and clamp negative declared figures to 0.
     *
     * The cashier path's persistSettlementDetails() aborts 422 on an unknown
     * tender_key or a negative figure. On the workstation replay path that would
     * dead-letter the sync item and orphan the till (issue #817), so we mirror
     * the controller's historical lenient skip+log behaviour instead.
     *
     * @param  list<array{tender_key?: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>  $details
     * @return list<array{tender_key: string, gross_amount: float, cancel_amount: float, terminal_batch_total: ?float, variance_reason: ?string}>
     */
    private function sanitizeWorkstationTenderDetails(TillSession $session, array $details): array
    {
        $known = $this->activeTenderTypesForBranch($session->branch_id)->keyBy('tender_key');
        $clean = [];
        foreach ($details as $row) {
            $key = $row['tender_key'] ?? null;
            if ($key === null || ! isset($known[$key])) {
                Log::warning('[workstation.till.close] unknown_tender_key', [
                    'tender_key' => $key,
                    'session_id' => $session->id,
                ]);

                continue;
            }
            $clean[] = [
                'tender_key' => $key,
                'gross_amount' => max(0.0, (float) ($row['gross_amount'] ?? 0)),
                'cancel_amount' => max(0.0, (float) ($row['cancel_amount'] ?? 0)),
                'terminal_batch_total' => $row['terminal_batch_total'] ?? null,
                'variance_reason' => $row['variance_reason'] ?? null,
            ];
        }

        return $clean;
    }

    /**
     * Huỷ ca mở nhầm. Chỉ cho phép khi chưa có OrderPayment stamped.
     */
    public function abandon(TillSession $session, ?string $reason = null): TillSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $till = $this->lockTillForSession($session);
            $session = TillSession::lockForUpdate()->findOrFail($session->id);

            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
                'SHIFT_NOT_OPEN',
                'Only an open or closing shift may be abandoned.'
            );

            $hasPayments = OrderPayment::where('till_session_id', $session->id)->exists();
            if ($hasPayments) {
                abort(response()->json([
                    'message' => 'Shift has stamped payments — close it instead of abandoning.',
                    'code' => 'SHIFT_HAS_PAYMENTS',
                ], 409));
            }

            $session->update([
                'status' => TillSessionStatusEnum::Abandoned->value,
                'abandoned_at' => now(),
                'abandon_reason' => $reason,
                'closed_by_id' => Auth::id(),
            ]);

            if ($till->current_session_id === $session->id) {
                $till->update(['current_session_id' => null]);
            }

            $session->logAudit('till_session_abandoned', [
                'session_code' => $session->session_code,
                'reason' => $reason,
            ]);

            return $this->findById($session->id);
        });
    }

    // =========================================================================
    //  Write — Plan-032 exit doors (force-abandon / expire / manual-settle)
    // =========================================================================

    /**
     * Manager dứt khoát huỷ ca treo. Bypasses the SHIFT_HAS_PAYMENTS guard
     * that cashier-driven abandon() enforces — the manager is asserting
     * "this shift is dead, release the till". Stamps force_abandoned=true
     * + force_abandoned_by_id + reason_code/detail for audit (DESIGN
     * Decision 5 + 8 dual-field reason).
     *
     * @param  string  $reasonCode  ForceAbandonReasonCodeEnum value
     * @param  ?string  $reasonDetail  Required (≥20 chars) only when reasonCode='other' — enforced by FormRequest, NOT here.
     *
     * @throws HttpException 409 SHIFT_TERMINAL_STATE if session is already
     *                       settled/abandoned/expired.
     */
    public function forceAbandon(
        TillSession $session,
        string $reasonCode,
        ?string $reasonDetail,
        User $manager,
    ): TillSession {
        return DB::transaction(function () use ($session, $reasonCode, $reasonDetail, $manager) {
            $till = $this->lockTillForSession($session);
            $session = TillSession::lockForUpdate()->findOrFail($session->id);

            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing],
                'SHIFT_TERMINAL_STATE',
                'Only open or closing shifts may be force-abandoned.'
            );

            $paymentsCount = OrderPayment::where('till_session_id', $session->id)->count();
            $cashEventsCount = TillCashEvent::where('session_id', $session->id)->count();

            $session->update([
                'status' => TillSessionStatusEnum::Abandoned->value,
                'abandoned_at' => now(),
                'closed_by_id' => $manager->id,
                'force_abandoned' => true,
                'force_abandoned_by_id' => $manager->id,
                'force_abandon_reason_code' => $reasonCode,
                'force_abandon_reason_detail' => $reasonDetail,
            ]);

            if ($till->current_session_id === $session->id) {
                $till->update(['current_session_id' => null]);
            }

            $session->logAudit('till_session_force_abandoned', [
                'session_code' => $session->session_code,
                'manager_id' => $manager->id,
                'reason_code' => $reasonCode,
                'reason_detail' => $reasonDetail,
                'payments_count' => $paymentsCount,
                'cash_events_count' => $cashEventsCount,
            ]);

            return $this->findById($session->id);
        });
    }

    /**
     * System-driven expire of a stale shift. Called by the scheduler command
     * `tills:expire-stale-shifts` (Phase 5). Re-checks the activity window
     * INSIDE the locked transaction to close the race between the outer
     * SELECT that picked this candidate and the inner UPDATE — see DESIGN
     * §Decisions §10. A payment landing in the window between SELECT and
     * lock makes us skip-and-no-op (returns null, NOT an error).
     *
     * Returns the updated session, or null when skipped (idempotent /
     * concurrent-payment / status already terminal).
     */
    public function expire(
        TillSession $session,
        string $reason,
        int $thresholdHours,
        int $activityWindowHours,
    ): ?TillSession {
        return DB::transaction(function () use ($session, $reason, $thresholdHours, $activityWindowHours) {
            $till = $this->lockTillForSession($session);
            /** @var TillSession $locked */
            $locked = TillSession::lockForUpdate()->find($session->id);
            if ($locked === null) {
                return null;
            }

            $currentStatus = $locked->status instanceof TillSessionStatusEnum
                ? $locked->status
                : TillSessionStatusEnum::from($locked->status);
            if (! in_array($currentStatus, [TillSessionStatusEnum::Open, TillSessionStatusEnum::Closing], true)) {
                // Idempotent: scheduler may re-tick on an already-terminal row.
                return null;
            }

            // Decision 10 — re-check activity inside the lock. Without this,
            // a payment committed between the scheduler's outer SELECT (no
            // recent payment) and our lockForUpdate would end up orphaned
            // on an expired session — audit corruption.
            $hasRecentActivity = OrderPayment::where('till_session_id', $locked->id)
                ->where('created_at', '>=', now()->subHours($activityWindowHours))
                ->exists();
            if ($hasRecentActivity) {
                Log::info('[pos.till] expire-skipped', [
                    'session_id' => $locked->id,
                    'session_code' => $locked->session_code,
                    'reason' => 'concurrent_payment',
                    'activity_window_hours' => $activityWindowHours,
                ]);

                return null;
            }

            $paymentsCount = OrderPayment::where('till_session_id', $locked->id)->count();
            $cashEventsCount = TillCashEvent::where('session_id', $locked->id)->count();
            $lastPaymentAt = OrderPayment::where('till_session_id', $locked->id)
                ->max('created_at');

            $locked->update([
                'status' => TillSessionStatusEnum::Expired->value,
                'expired_at' => now(),
                'expire_reason' => $reason,
                'expire_threshold_hours' => $thresholdHours,
                // closed_by_id stays NULL — no human in the loop.
            ]);

            if ($till->current_session_id === $locked->id) {
                $till->update(['current_session_id' => null]);
            }

            $locked->logAudit('till_session_expired', [
                'session_code' => $locked->session_code,
                'reason' => $reason,
                'threshold_hours' => $thresholdHours,
                'opened_at' => $locked->opened_at?->toIso8601String(),
                'last_payment_at' => $lastPaymentAt,
                'payments_count' => $paymentsCount,
                'cash_events_count' => $cashEventsCount,
            ]);

            return $this->findById($locked->id);
        });
    }

    /**
     * Manager reconcile of an expired session, post-hoc (DESIGN Decision 8b).
     * Same reconciliation math as close() but accepts `expired` as the
     * starting status and allows two optional overrides for cases where the
     * cashier disappeared mid-shift:
     *
     *   - opening_counts_override: replace recorded opening counts with the
     *     manager's recount. Emits a SEPARATE `till_session_opening_overridden`
     *     audit row carrying before+after payloads.
     *   - post_hoc_cash_events: insert cash_events the cashier never
     *     recorded (drops/paid-in/paid-out). Each marked `manual_adjustment=true`
     *     with `recorded_by_id=manager.id`.
     *
     * @param  array{
     *     closing_counts: list<array{denomination_id: string, quantity: int}>,
     *     tender_details: list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>,
     *     closing_note?: ?string,
     *     manual_settle_reason: string,
     *     opening_counts_override?: ?list<array{denomination_id: string, quantity: int}>,
     *     post_hoc_cash_events?: ?list<array{event_type: string, amount: float|int|string, currency_code?: string, reason?: ?string, occurred_at?: string}>,
     *   }  $data
     *
     * @throws HttpException 409 SHIFT_NOT_EXPIRED if session is not in expired status.
     */
    public function manualSettle(TillSession $session, array $data, User $manager): TillSession
    {
        return DB::transaction(function () use ($session, $data, $manager) {
            $till = $this->lockTillForSession($session);
            $session = TillSession::lockForUpdate()->findOrFail($session->id);

            $this->assertStatus(
                $session,
                [TillSessionStatusEnum::Expired],
                'SHIFT_NOT_EXPIRED',
                'Manual-settle is only allowed on expired sessions.'
            );

            $currency = $session->default_currency_code ?? 'JPY';
            $openingOverridden = false;
            $postHocCount = 0;

            // 1. Optional override of opening counts (Decision 8b).
            if (! empty($data['opening_counts_override'])) {
                $beforeRows = TillCashDenominationCount::where('session_id', $session->id)
                    ->where('count_phase', TillCountPhaseEnum::Opening->value)
                    ->get(['denomination_id', 'quantity', 'subtotal_amount'])
                    ->map(fn ($r) => $r->only(['denomination_id', 'quantity', 'subtotal_amount']))
                    ->all();
                TillCashDenominationCount::where('session_id', $session->id)
                    ->where('count_phase', TillCountPhaseEnum::Opening->value)
                    ->delete();
                $newFloat = $this->persistDenominationCounts(
                    $session,
                    $currency,
                    TillCountPhaseEnum::Opening,
                    $data['opening_counts_override']
                );
                $session->update(['opening_float_amount' => $newFloat]);
                $openingOverridden = true;

                $session->logAudit('till_session_opening_overridden', [
                    'session_code' => $session->session_code,
                    'manager_id' => $manager->id,
                    'manual_settle_reason' => $data['manual_settle_reason'],
                    'before' => $beforeRows,
                    'after' => $data['opening_counts_override'],
                    'new_opening_float_amount' => (float) $newFloat,
                ]);
            }

            // 2. Optional post-hoc cash events (Decision 8b).
            if (! empty($data['post_hoc_cash_events'])) {
                foreach ($data['post_hoc_cash_events'] as $row) {
                    TillCashEvent::create([
                        'session_id' => $session->id,
                        'event_type' => $row['event_type'],
                        'amount' => (float) $row['amount'],
                        'currency_code' => $row['currency_code'] ?? $currency,
                        'reason' => $row['reason'] ?? null,
                        'performed_by_id' => $manager->id,
                        'occurred_at' => $row['occurred_at'] ?? now(),
                        'manual_adjustment' => true,
                    ]);
                    $postHocCount++;
                }
            }

            // 3. Closing-phase denomination counts (overwrite any draft).
            TillCashDenominationCount::where('session_id', $session->id)
                ->where('count_phase', TillCountPhaseEnum::Closing->value)
                ->delete();
            $countedCash = $this->persistDenominationCounts(
                $session,
                $currency,
                TillCountPhaseEnum::Closing,
                $data['closing_counts']
            );

            // 4. Reconciliation snapshot after override + post-hoc apply.
            $recon = $this->reconcile($session->fresh());

            // 5. Per-tender settlement details (same flow as close()).
            $tolerance = (float) ($till->variance_tolerance_amount ?? 0);
            $varianceMissing = $this->persistSettlementDetails(
                $session,
                $data['tender_details'] ?? [],
                $recon['tenders'],
                $recon['category_expected'],
                $tolerance
            );

            // 6. Cash variance reason check (inherits close() math).
            $expectedCash = (float) $recon['cash']['expected_cash'];
            $cashVariance = round($countedCash - $expectedCash, 2);
            $cashReasonMissing = abs($cashVariance) > $tolerance
                && empty(trim((string) ($data['closing_note'] ?? '')))
                && ! $this->cashReasonProvided($data['tender_details'] ?? []);

            if ($varianceMissing || $cashReasonMissing) {
                abort(response()->json([
                    'message' => 'A variance reason is required for at least one tender/cash row.',
                    'code' => 'VARIANCE_REASON_REQUIRED',
                    'tenders_missing_reason' => $varianceMissing,
                    'cash_missing_reason' => $cashReasonMissing,
                ], 422));
            }

            // 7. Stamp session.
            $session->update([
                'status' => TillSessionStatusEnum::Settled->value,
                'closed_at' => now(),
                'closed_by_id' => $manager->id,
                'closing_note' => $data['closing_note'] ?? $session->closing_note,
                'expected_cash_amount' => $expectedCash,
                'counted_cash_amount' => $countedCash,
                'cash_variance_amount' => $cashVariance,
                // #552 — freeze recognized revenue at manual-settle too, so an
                // expired shift reconciled by a manager is equally immutable.
                ...$this->revenueSnapshotColumns($recon),
                // plan-046 — a manager-recovered shift is a REAL settlement with
                // real money, so it must be a chain member. Without these two
                // fields chainSummary()'s `whereIn(settlement_kind,…)` filter
                // skipped it and the aggregate silently under-reported the whole
                // chain by that shift's cash, tax and revenue.
                //
                // `final` is the correct kind: the shift expired rather than
                // being handed over, so per R5 it must END the chain — the next
                // open starts a fresh one. Recording it as a member makes its
                // money count; recording it as `final` stops the chain
                // continuing through a shift nobody actually handed over.
                'settlement_kind' => TillSettlementKindEnum::Final->value,
                'settlement_snapshot' => $this->buildSettlementSnapshot(
                    $session, $recon, (float) $countedCash, $cashVariance
                ),
            ]);

            $session->logAudit('till_session_manual_settled', [
                'session_code' => $session->session_code,
                'prior_status' => TillSessionStatusEnum::Expired->value,
                'manager_id' => $manager->id,
                'manual_settle_reason' => $data['manual_settle_reason'],
                'opening_counts_overridden' => $openingOverridden,
                'post_hoc_cash_events' => $postHocCount,
                'expected_cash' => $expectedCash,
                'counted_cash' => (float) $countedCash,
                'cash_variance' => $cashVariance,
            ]);

            return $this->findById($session->id);
        });
    }

    // =========================================================================
    //  Compute — Reconciliation (read-only)
    // =========================================================================

    /**
     * Compute revenue + expected cash + per-tender expected for a session.
     * Read-only — used by GET /pos/till/sessions/{id}/reconciliation and as
     * the expected-side snapshot for close().
     *
     * @return array{
     *   revenue: array{gross: float, net: float, tax: float, discount: float, currency_code: string},
     *   cash: array{opening_float: float, cash_sales: float, cash_tips: float, paid_in: float, paid_out: float, loan_from_safe: float, pickup_to_safe: float, expected_cash: float},
     *   tenders: list<array{tender_key: string, category: string, parent: ?string, expected_amount: ?float}>,
     *   category_expected: array<string, float>,
     * }
     */
    public function reconcile(TillSession $session): array
    {
        $currency = $session->default_currency_code ?? 'JPY';

        // Payments by method (drawer-affecting rows, only this session).
        //
        // Issue #523 — cross-session refund accounting. A shift's per-method
        // total is the NET cash movement in its drawer, which has two parts:
        //
        //   (1) sale originals (refund_of_id IS NULL): positive amounts that
        //       entered the drawer during THIS shift. We count status
        //       `succeeded` AND `refunded` — a sale later refunded in a
        //       different shift still landed in this drawer at sale time, so
        //       dropping it would retroactively shrink an already-settled
        //       Z-report the moment someone re-reconciles it.
        //
        //   (2) refund rows (refund_of_id IS NOT NULL): negative amounts that
        //       left the drawer during THIS shift (stamped at refund time by
        //       OrderPaymentService::refund). status is always `succeeded`.
        //
        // Same-shift sale+refund nets to zero (original +X, refund -X); a
        // cross-shift refund leaves +X in the sale shift and -X in the refund
        // shift — each drawer balances to its own physical count.
        $paymentSums = OrderPayment::query()
            ->where('order_payments.till_session_id', $session->id)
            ->where(function ($q) {
                $q->where(function ($sale) {
                    $sale->whereNull('order_payments.refund_of_id')
                        ->whereIn('order_payments.status', [
                            PaymentStatusEnum::Succeeded->value,
                            PaymentStatusEnum::Refunded->value,
                        ]);
                })->orWhere(function ($refund) {
                    $refund->whereNotNull('order_payments.refund_of_id')
                        ->where('order_payments.status', PaymentStatusEnum::Succeeded->value);
                });
            })
            ->join('payment_methods as pm', 'pm.id', '=', 'order_payments.payment_method_id')
            ->selectRaw('pm.code as code, SUM(order_payments.amount) as amount')
            ->groupBy('pm.code')
            ->pluck('amount', 'code')
            ->map(fn ($v) => (float) $v)
            ->all();

        // `card_terminal` cards are swiped on the SAME physical payment terminal as
        // `card`, so they land in the same credit section of its batch slip and
        // must reconcile as one line. Both readers below key off 'card': the
        // `credit` anchor tender (payment_method_code='card', see
        // TillTenderTypeSeeder) and $categoryExpected[Card]. Left un-merged, the
        // sum sits under its own key, invisible to both — every close would show
        // expected credit = 0 against a terminal slip holding real money, a
        // phantom variance the cashier can never settle.
        if (isset($paymentSums['card_terminal'])) {
            $paymentSums['card'] = ($paymentSums['card'] ?? 0) + $paymentSums['card_terminal'];
        }

        $cashSales = (float) ($paymentSums['cash'] ?? 0);

        // #555 M2 — cash tips physically stay in the drawer. OrderPaymentService
        // computes change = tendered − amount − tip, so the drawer retains
        // (amount + tip) for every cash payment, but cash_sales = SUM(amount)
        // excludes the tip. Without adding it back, expected_cash is short by
        // exactly the face-cash tips and every shift closes "over". Card/QR tips
        // never touch the drawer, so we only add cash-method tips here.
        $cashTips = (float) OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->where('status', PaymentStatusEnum::Succeeded->value)
            ->whereNull('refund_of_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'order_payments.payment_method_id')
            ->where('pm.code', 'cash')
            ->sum('order_payments.tip_amount');

        // Revenue from the distinct CustomerOrders behind those payments.
        // Same as (1) above: recognize revenue in the SALE shift and keep it
        // there — a later cross-shift refund must not retroactively erase the
        // sale from a settled shift. Refund rows never add orders here (they
        // reduce the drawer, not the recognized revenue of the sale shift).
        $orderIds = OrderPayment::query()
            ->where('till_session_id', $session->id)
            ->whereNull('refund_of_id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->distinct()
            ->pluck('customer_order_id');

        $revenue = ['gross' => 0.0, 'net' => 0.0, 'tax' => 0.0, 'discount' => 0.0];
        if ($orderIds->isNotEmpty()) {
            // customer_orders keeps subtotal/total/total_tip; discount, service
            // charge and tax come from order_conditions. There is no net_amount
            // column — net is derived as total - ledger tax.
            $rollup = $this->orders->revenueForOrders(
                array_map('strval', $orderIds instanceof SupportCollection
                    ? $orderIds->all() : (array) $orderIds)
            );
            $revenue = [
                'gross' => $rollup->gross,
                'net' => $rollup->net,
                'tax' => $rollup->tax,
                'discount' => $rollup->discount,
            ];
        }

        // Mid-shift cash events.
        $paidIn = (float) TillCashEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', TillCashEventTypeEnum::PaidIn->value)
            ->sum('amount');
        $paidOut = (float) TillCashEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', TillCashEventTypeEnum::PaidOut->value)
            ->sum('amount');
        // Safe-flow events move real cash in/out of the drawer and MUST be
        // reflected in expected_cash (issue #530): loan_from_safe adds cash to
        // the drawer (like paid_in), pickup_to_safe removes it (like paid_out).
        // Omitting them made the shift report short/over by the moved amount.
        $loanFromSafe = (float) TillCashEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', TillCashEventTypeEnum::LoanFromSafe->value)
            ->sum('amount');
        $pickupToSafe = (float) TillCashEvent::query()
            ->where('session_id', $session->id)
            ->where('event_type', TillCashEventTypeEnum::PickupToSafe->value)
            ->sum('amount');

        $openingFloat = (float) ($session->opening_float_amount ?? 0);
        $expectedCash = round(
            $openingFloat + $cashSales + $cashTips + $paidIn + $loanFromSafe - $paidOut - $pickupToSafe,
            2,
        );

        // Per-tender expected and category rollups.
        $tenderTypes = $this->activeTenderTypesForBranch($session->branch_id);

        // Category expected mirrors the seeded PaymentMethod semantics:
        //   - `card`     ← PaymentMethod.code='card'      (クレジット)
        //   - `emoney`   ← PaymentMethod.code='e_wallet'  (電子マネー — its ja
        //                  name is EXACTLY the emoney category label; iD/WAON/
        //                  nanaco… tap-IC brands settle here)
        //   - `qr`       ← PaymentMethod.code='transfer'  (振込 — the only
        //                  remaining non-cash aggregate; the 9 QR sub-brands
        //                  reconcile at the category level)
        // Previously e_wallet was summed into `qr` and `emoney` was hardcoded 0,
        // so any e-money declared at close produced an unbalanceable variance
        // (declared − 0) that could never settle without a reason.
        $categoryExpected = [
            TillTenderSystemCategoryEnum::Cash->value => $cashSales,
            TillTenderSystemCategoryEnum::Card->value => (float) ($paymentSums['card'] ?? 0),
            TillTenderSystemCategoryEnum::Qr->value => (float) ($paymentSums['transfer'] ?? 0),
            TillTenderSystemCategoryEnum::Emoney->value => (float) ($paymentSums['e_wallet'] ?? 0),
        ];

        $tenders = [];
        foreach ($tenderTypes as $type) {
            $category = $type->category instanceof TillTenderSystemCategoryEnum
                ? $type->category->value
                : $type->category;
            $expected = null;
            if ($type->is_expected_anchor) {
                $methodCode = $type->payment_method_code;
                $expected = $methodCode !== null
                    ? (float) ($paymentSums[$methodCode] ?? 0)
                    : (float) ($categoryExpected[$category] ?? 0);
            }
            $tenders[] = [
                'tender_key' => $type->tender_key,
                'category' => $category,
                'parent' => $type->parent_tender_key,
                'expected_amount' => $expected,
            ];
        }

        // #552 — a settled shift's recognized revenue is FROZEN at close. Once
        // the snapshot columns are populated (close / settleFromWorkstation /
        // manualSettle), return them verbatim so no later payment mutation — a
        // pending card confirmed after the shift closed, a post-close order edit
        // — can retro-change an already-chốt Z-report. Open/closing shifts have
        // null columns and fall through to the live figures computed above.
        if ($session->settled_gross_amount !== null) {
            $revenue = [
                'gross' => (float) $session->settled_gross_amount,
                'net' => (float) $session->settled_net_amount,
                'tax' => (float) $session->settled_tax_amount,
                'discount' => (float) $session->settled_discount_amount,
            ];
        }

        return [
            'revenue' => array_merge($revenue, ['currency_code' => $currency]),
            'cash' => [
                'opening_float' => $openingFloat,
                'cash_sales' => $cashSales,
                'cash_tips' => $cashTips,
                'paid_in' => $paidIn,
                'paid_out' => $paidOut,
                'loan_from_safe' => $loanFromSafe,
                'pickup_to_safe' => $pickupToSafe,
                'expected_cash' => $expectedCash,
            ],
            'tenders' => $tenders,
            'category_expected' => $categoryExpected,
        ];
    }

    /**
     * Build the frozen recognized-revenue snapshot columns (#552) from a
     * reconcile() result, for stamping onto a session at settle time. Kept as a
     * helper so close(), settleFromWorkstation() and manualSettle() freeze the
     * exact same four figures reconcile() later reads back.
     *
     * @param  array{revenue: array{gross: float, net: float, tax: float, discount: float}}  $recon
     * @return array{settled_gross_amount: float, settled_net_amount: float, settled_tax_amount: float, settled_discount_amount: float}
     */
    private function revenueSnapshotColumns(array $recon): array
    {
        return [
            'settled_gross_amount' => (float) ($recon['revenue']['gross'] ?? 0),
            'settled_net_amount' => (float) ($recon['revenue']['net'] ?? 0),
            'settled_tax_amount' => (float) ($recon['revenue']['tax'] ?? 0),
            'settled_discount_amount' => (float) ($recon['revenue']['discount'] ?? 0),
        ];
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    private function lockTill(string $branchId, string $tillCode = self::DEFAULT_TILL_CODE): Till
    {
        $till = Till::query()
            ->where('branch_id', $branchId)
            ->where('till_code', $tillCode)
            ->lockForUpdate()
            ->first();
        if ($till === null) {
            // First-touch: bootstrap and re-lock.
            $this->resolveTillForBranch($branchId, $tillCode);
            $till = Till::query()
                ->where('branch_id', $branchId)
                ->where('till_code', $tillCode)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $till;
    }

    /**
     * Lock the till a SESSION actually belongs to (by its till_id), not the
     * branch's default 'MAIN' till.
     *
     * Bugfix: close/abandon/forceAbandon/expire/manualSettle used to lock
     * `lockTill($session->branch_id)` — always the MAIN till. On a branch with
     * more than one till (REG1/REG2/…), a shift opened on a non-MAIN till was
     * settled/abandoned on the SESSION but its own till's `current_session_id`
     * was never cleared (the clear targeted MAIN). The stale pointer then kept
     * the till "occupied" forever, blocking the plan-031 currency guard AND the
     * plan-043 tax-mode guard (both key off `current_session_id`), and preventing
     * a new shift from opening on that till. Locking the session's own till also
     * reads the correct per-till variance tolerance at settle time.
     *
     * Falls back to the branch default for a legacy session with no till_id.
     */
    private function lockTillForSession(TillSession $session): Till
    {
        if (! empty($session->till_id)) {
            $till = Till::query()
                ->where('id', $session->till_id)
                ->lockForUpdate()
                ->first();
            if ($till !== null) {
                return $till;
            }
        }

        return $this->lockTill($session->branch_id);
    }

    /**
     * Reject a denomination whose currency is set and differs from the shift's.
     *
     * L4 (#555) — face values are summed straight into counted/opening cash; a
     * denomination carrying a different currency_code than the shift (e.g. a
     * $100 bill counted in a ¥ shift) would add 100 to the yen total and
     * silently corrupt the cash variance. A NULL currency_code is a generic
     * denomination that adopts the shift currency, so only a set-and-mismatched
     * code is blocked. Public so the workstation openSession loop enforces the
     * exact same guard the pos persist path does (issue #817).
     */
    public function assertDenominationCurrency(Denomination $denom, string $currency): void
    {
        if ($denom->currency_code !== null
            && strtoupper((string) $denom->currency_code) !== strtoupper($currency)) {
            abort(response()->json([
                'message' => "Denomination currency ({$denom->currency_code}) does not match the shift currency ({$currency}).",
                'code' => 'DENOMINATION_CURRENCY_MISMATCH',
                'denomination_id' => (string) $denom->id,
                'denomination_currency' => (string) $denom->currency_code,
                'shift_currency' => $currency,
            ], 422));
        }
    }

    /**
     * @param  list<array{denomination_id: string, quantity: int}>  $inputs
     */
    private function persistDenominationCounts(
        TillSession $session,
        string $currency,
        TillCountPhaseEnum $phase,
        array $inputs,
    ): float {
        $total = 0.0;
        foreach ($inputs as $row) {
            /** @var Denomination $denom */
            $denom = Denomination::findOrFail($row['denomination_id']);
            $qty = (int) $row['quantity'];
            if ($qty < 0) {
                abort(422, 'Denomination quantity must be ≥ 0.');
            }

            // L4 (#555) — reject foreign-currency denominations (see
            // assertDenominationCurrency for the full rationale).
            $this->assertDenominationCurrency($denom, $currency);
            $subtotal = round((float) $denom->value * $qty, 2);
            TillCashDenominationCount::create([
                'session_id' => $session->id,
                'count_phase' => $phase->value,
                'quantity' => $qty,
                'subtotal_amount' => $subtotal,
                'currency_code' => $denom->currency_code ?? $currency,
                'denomination_value' => $denom->value,
                'denomination_kind' => $denom->kind instanceof \BackedEnum ? $denom->kind->value : $denom->kind,
                'denomination_id' => $denom->id,
            ]);
            $total += $subtotal;
        }

        return round($total, 2);
    }

    /**
     * @param  list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>  $details
     */
    private function persistTenderDetailsDraft(TillSession $session, array $details): void
    {
        $tenderTypes = $this->activeTenderTypesForBranch($session->branch_id)->keyBy('tender_key');
        foreach ($details as $row) {
            $key = $row['tender_key'] ?? null;
            if ($key === null || ! isset($tenderTypes[$key])) {
                abort(422, "Unknown tender_key: {$key}");
            }
            $type = $tenderTypes[$key];
            $gross = (float) ($row['gross_amount'] ?? 0);
            $cancel = (float) ($row['cancel_amount'] ?? 0);
            $net = round($gross - $cancel, 2);
            TillSettlementTenderDetail::updateOrCreate(
                ['session_id' => $session->id, 'tender_key' => $key],
                [
                    'category' => $type->category instanceof TillTenderSystemCategoryEnum
                        ? $type->category->value
                        : $type->category,
                    'currency_code' => $session->default_currency_code ?? 'JPY',
                    'declared_gross_amount' => $gross,
                    'declared_cancel_amount' => $cancel,
                    'declared_amount' => $net,
                    'terminal_batch_total' => $row['terminal_batch_total'] ?? null,
                    'variance_reason' => $row['variance_reason'] ?? null,
                    'tender_type_id' => $type->id,
                ]
            );
        }
    }

    /**
     * Persist final settlement details with expected/variance + enforce
     * variance-reason rule. Returns true if any tender variance > tolerance
     * with no reason supplied.
     *
     * @param  list<array{tender_key: string, gross_amount?: float, cancel_amount?: float, terminal_batch_total?: ?float, variance_reason?: ?string}>  $inputs
     * @param  list<array{tender_key: string, category: string, parent: ?string, expected_amount: ?float}>  $tendersComputed
     * @param  array<string, float>  $categoryExpected
     */
    private function persistSettlementDetails(
        TillSession $session,
        array $inputs,
        array $tendersComputed,
        array $categoryExpected,
        float $tolerance,
    ): bool {
        // Wipe any draft rows so close() result is canonical.
        TillSettlementTenderDetail::where('session_id', $session->id)->delete();

        $tenderTypes = $this->activeTenderTypesForBranch($session->branch_id)->keyBy('tender_key');
        $inputByKey = [];
        foreach ($inputs as $row) {
            if (isset($row['tender_key'])) {
                $inputByKey[$row['tender_key']] = $row;
            }
        }
        $expectedByKey = [];
        foreach ($tendersComputed as $row) {
            $expectedByKey[$row['tender_key']] = $row;
        }

        $reasonMissing = false;
        $categoryDeclared = [];

        foreach ($tenderTypes as $key => $type) {
            $input = $inputByKey[$key] ?? [];
            $gross = (float) ($input['gross_amount'] ?? 0);
            $cancel = (float) ($input['cancel_amount'] ?? 0);
            if ($gross < 0 || $cancel < 0) {
                abort(422, "Negative declared figure for tender {$key}.");
            }
            $net = round($gross - $cancel, 2);
            $category = $type->category instanceof TillTenderSystemCategoryEnum
                ? $type->category->value
                : $type->category;
            $categoryDeclared[$category] = ($categoryDeclared[$category] ?? 0) + $net;

            $expected = $expectedByKey[$key]['expected_amount'] ?? null;
            // Cash reconciles physically via the drawer count (counted_cash vs
            // expected_cash in close()), NOT as a payment-terminal settlement
            // tender — cash never touches the terminal, so the cashier declares
            // it 0 on the slip. Carrying the cashSales expected onto the cash
            // settlement row would reconcile cash a SECOND time and surface a
            // phantom −cashSales variance that blocks close even when the drawer
            // balances. Drop the expected/variance for the cash category so the
            // row is a plain record of the (zero) terminal declaration.
            if ($category === TillTenderSystemCategoryEnum::Cash->value) {
                $expected = null;
            }
            $variance = $expected === null ? null : round($net - (float) $expected, 2);

            $reason = $input['variance_reason'] ?? null;
            if ($variance !== null && abs($variance) > $tolerance && empty(trim((string) $reason))) {
                $reasonMissing = true;
            }

            TillSettlementTenderDetail::create([
                'session_id' => $session->id,
                'tender_key' => $key,
                'category' => $category,
                'currency_code' => $session->default_currency_code ?? 'JPY',
                'expected_amount' => $expected,
                'declared_gross_amount' => $gross,
                'declared_cancel_amount' => $cancel,
                'declared_amount' => $net,
                'terminal_batch_total' => $input['terminal_batch_total'] ?? null,
                'variance_amount' => $variance,
                'variance_reason' => $reason,
                'tender_type_id' => $type->id,
            ]);
        }

        // Unknown tender_keys in input ⇒ 422
        foreach ($inputByKey as $key => $_) {
            if (! isset($tenderTypes[$key])) {
                abort(422, "Unknown tender_key: {$key}");
            }
        }

        // Category-level variance reason check (qr/emoney ONLY): require a
        // reason if a non-anchored category's rolled-up declared diverges from
        // its expected beyond tolerance & no row in that category supplied one.
        //
        // Anchored categories are deliberately excluded (DESIGN Decision 4):
        //   - `cash` reconciles via the drawer, never as a terminal tender —
        //     including it here double-counts cash into a phantom variance.
        //   - `card` reconciles per-row on its anchor above; the rollup would
        //     re-flag the identical variance.
        $categoryRollupOnly = [
            TillTenderSystemCategoryEnum::Qr->value,
            TillTenderSystemCategoryEnum::Emoney->value,
        ];
        foreach ($categoryExpected as $cat => $catExpected) {
            if (! in_array($cat, $categoryRollupOnly, true)) {
                continue;
            }
            $declared = $categoryDeclared[$cat] ?? 0.0;
            $catVariance = round($declared - $catExpected, 2);
            if (abs($catVariance) <= $tolerance) {
                continue;
            }
            $hasReason = TillSettlementTenderDetail::where('session_id', $session->id)
                ->where('category', $cat)
                ->whereNotNull('variance_reason')
                ->where('variance_reason', '!=', '')
                ->exists();
            if (! $hasReason) {
                $reasonMissing = true;
            }
        }

        return $reasonMissing;
    }

    /**
     * @param  list<array{tender_key?: string, variance_reason?: ?string}>  $details
     */
    private function cashReasonProvided(array $details): bool
    {
        foreach ($details as $row) {
            if (($row['tender_key'] ?? null) === 'cash' && ! empty(trim((string) ($row['variance_reason'] ?? '')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, TillTenderType>
     */
    private function activeTenderTypesForBranch(string $branchId)
    {
        return TillTenderType::query()
            ->where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * SHIFT-YYYYMMDD-NNN — globally sequenced per day.
     *
     * `till_sessions.session_code` is unique GLOBALLY (not scoped to org or
     * branch), so the running number must be derived from the global per-day
     * max. A per-org count restarts at 001 for every tenant and collides
     * across orgs the moment a second org opens a shift on the same day
     * (Duplicate entry 'SHIFT-YYYYMMDD-001'). open() retries on the rare
     * concurrent race.
     */
    private function generateSessionCode(Carbon $when): string
    {
        $base = 'SHIFT-'.$when->format('Ymd').'-';

        // Take the MAX of the NUMERIC suffix, not the lexicographic max of the
        // whole code. CAST(... AS UNSIGNED) turns any stray non-numeric suffix
        // (e.g. a SHIFT-YYYYMMDD-OPEN1 row synced from an offline workstation)
        // into 0, so it can't sort above the numbers and reset the sequence to
        // 001 → 1062 Duplicate entry. Mirrors the order_code generator.
        $lastNum = (int) TillSession::query()
            ->where('session_code', 'like', "{$base}%")
            ->selectRaw('MAX(CAST(SUBSTRING(session_code, ?) AS UNSIGNED)) as last_num', [strlen($base) + 1])
            ->value('last_num');

        return $base.str_pad((string) ($lastNum + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTillAttributesForBranch(string $branchId): array
    {
        // Branches link to Brand via console_brand_id (Brand.console_brand_id),
        // not via a local brand_id FK. Resolve the local Brand UUID here.
        $branch = DB::table('branches')
            ->where('id', $branchId)
            ->first(['console_brand_id', 'console_organization_id']);

        $brandId = null;
        if ($branch?->console_brand_id) {
            $brandId = DB::table('brands')
                ->where('console_brand_id', $branch->console_brand_id)
                ->value('id');
        }

        $organizationId = null;
        if ($branch?->console_organization_id) {
            $organizationId = DB::table('organizations')
                ->where('console_organization_id', $branch->console_organization_id)
                ->value('id');
        }

        return [
            'default_currency_code' => 'JPY',
            'variance_tolerance_amount' => 0,
            'is_active' => true,
            'branch_id' => $branchId,
            'brand_id' => $brandId,
            'organization_id' => $organizationId,
        ];
    }

    /**
     * @param  list<TillSessionStatusEnum>  $allowed
     */
    private function assertStatus(TillSession $session, array $allowed, string $code, string $message): void
    {
        $current = $session->status instanceof TillSessionStatusEnum
            ? $session->status
            : TillSessionStatusEnum::from($session->status);
        foreach ($allowed as $s) {
            if ($current === $s) {
                return;
            }
        }
        abort(response()->json([
            'message' => $message,
            'code' => $code,
            'status' => $current->value,
        ], 409));
    }
}
