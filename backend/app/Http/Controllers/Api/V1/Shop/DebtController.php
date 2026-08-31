<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Contracts\PartPaidOrder;
use App\Services\Order\Contracts\PartPaidOrderReads;
use Illuminate\Database\Query\Builder;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\Contracts\CustomerDirectory;
use App\Services\Order\Contracts\BranchDebtOrderAnchors;
use App\Services\Payment\Contracts\OpenAccountDebt;
use App\Services\Payment\Contracts\OpenAccountDebtReads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Plan-038 T10.5 — DebtController surfaces open on-account balances per
 * customer. A row counts as "open" when there's a confirmed on_account
 * payment AND no other order_payment whose metadata.settles_payment_id
 * points back to it.
 *
 * Powers:
 *   - GET /api/v1/shops/{shopSlug}/debts            (this controller)
 *   - admin-web /admin/debts table (out of scope here; endpoint only)
 *   - pos-web "Tra cứu nợ" dialog
 *
 * ## Đây là tầng LẮP RÁP, không phải nơi giữ luật (#1993)
 *
 * Toàn bộ luật tiền — bù trừ hoàn, settlement còn sống, xoá mềm — từng là một
 * câu `DB::table` ngay trong controller này. Nó sống được ở đây **chỉ vì**
 * Composition là tầng duy nhất `architecture:raw-table-reads` bỏ qua, tức một
 * ngoại lệ về ranh giới đang bị dùng làm chỗ ở cho luật tiền.
 *
 * Giờ mỗi module trả lời phần của mình và controller ghép lại:
 *
 *   {@see OpenAccountDebtReads}     Payments  — khoản nợ nào còn mở, còn bao nhiêu
 *   {@see BranchDebtOrderAnchors}   Ordering  — ai nợ, đơn nào, có thuộc chi nhánh không
 *   {@see CustomerDirectory}        Customer  — gọi tên người nợ ra sao
 *
 * Ba lượt truy vấn thay vì một, và trần đã đo trước khi chấp nhận: tập trung
 * gian là **nợ đang mở của MỘT chi nhánh** — không phải toàn bộ payment. Cùng
 * lập luận `BranchOrderReads` dùng; đừng chép kết luận này sang một truy vấn
 * không có trần.
 *
 * `partPaid()` bên dưới thì CHƯA đi theo — nó đo một thứ khác (đơn chưa trả đủ)
 * và việc gom định nghĩa đó với `CustomerOutstandingOrderService` là #1992.
 */
class DebtController extends Controller
{
    public function __construct(
        private readonly PartPaidOrderReads $partPaidOrders,
        private readonly OpenAccountDebtReads $debts,
        private readonly BranchDebtOrderAnchors $orderAnchors,
        private readonly CustomerDirectory $customers,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/debts',
        summary: 'List open on-account debts grouped by customer',
        description: 'Aggregates confirmed payment_method.type=on_account rows that have no offsetting settlement (no other payment whose metadata.settles_payment_id matches). Grouped by customer.',
        tags: ['Shop', 'Debts'],
        security: [['bearer_token' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Open debts grouped by customer.'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $limit = $validated['limit'] ?? 50;

        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $byCustomer = $this->openDebtsByCustomer(
            $shop,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        if (! empty($validated['customer_id'])) {
            $byCustomer = array_intersect_key($byCustomer, [$validated['customer_id'] => true]);
        }

        // #821 A11 — `cursor` từng được validate và `next_cursor` từng được trả
        // về, nhưng truy vấn KHÔNG áp nó: sang trang lại phục vụ đúng trang 1,
        // nên một shop có 300 người nợ chỉ bao giờ hiện 50 và tổng nợ mà quản lý
        // nhìn thấy bị cắt cụt trong im lặng. Khoá sắp xếp là `customer_id`, nên
        // con trỏ là keyset trên chính cột đó.
        ksort($byCustomer);
        if (! empty($validated['cursor'])) {
            $cursor = (string) $validated['cursor'];
            $byCustomer = array_filter(
                $byCustomer,
                static fn (string $customerId): bool => $customerId > $cursor,
                ARRAY_FILTER_USE_KEY,
            );
        }

        $page = array_slice($byCustomer, 0, $limit, preserve_keys: true);
        $hasMore = count($byCustomer) > $limit;

        // Tên khách tra SAU khi đã cắt trang: một shop có 300 người nợ thì trang
        // này chỉ cần 50 cái tên.
        $contacts = $this->customers->entriesByIds(array_map(strval(...), array_keys($page)));

        $data = [];
        foreach ($page as $customerId => $debts) {
            $contact = $contacts[$customerId] ?? null;
            $timestamps = array_map(static fn (OpenAccountDebt $d): string => $d->createdAt, $debts);

            $data[] = [
                'customer_id' => $customerId,
                'customer_name' => $contact?->displayName,
                'customer_phone' => $contact?->phone,
                'customer_tax_code' => $contact?->taxCode,
                'open_debt_count' => count($debts),
                // Chuỗi hai chữ số thập phân, giữ đúng hình dạng mà `SUM()` trên
                // cột `decimal(15,2)` trả ra ở bản cũ.
                'open_debt_total' => number_format(
                    array_sum(array_map(static fn (OpenAccountDebt $d): float => $d->netAmount, $debts)),
                    2,
                    '.',
                    '',
                ),
                'oldest_debt_at' => min($timestamps),
                'latest_debt_at' => max($timestamps),
            ];
        }

        return response()->json([
            'data' => $data,
            'next_cursor' => $hasMore && $data !== []
                ? $data[array_key_last($data)]['customer_id']
                : null,
            'shop_slug' => $shop->slug,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Nợ đang mở của shop, gom theo khách — nền chung của cả hai màn hình.
     *
     * Ba module trả lời ba phần và ghép ở đây; không phần nào biết phần kia:
     *
     *   1. Payments  — khoản nợ nào còn mở, còn bao nhiêu (đã bù trừ hoàn);
     *   2. Ordering  — mỗi khoản nằm trên đơn nào, đơn đó của ai, và **có thuộc
     *      shop này không**. Đây là chỗ cách ly tenant thật sự đứng: bộ lọc
     *      `order_payments.branch_id` ở bước 1 chỉ là lọc rẻ, còn chi nhánh có
     *      thẩm quyền là chi nhánh của ĐƠN. Nó cũng là chỗ đơn đã xoá mềm rụng
     *      đi — theo cấu trúc, vì cổng đó đọc qua model;
     *   3. khoản nợ trên đơn KHÔNG có khách bị bỏ. `OrderPaymentStoreRequest`
     *      chặn chuyện đó từ đầu vào (`customer_required_for_debt`), nhưng một
     *      màn hình tra cứu THEO KHÁCH thì không có chỗ nào để hiện nó cả.
     *
     * @return array<string, list<OpenAccountDebt>> khoá là id khách
     */
    private function openDebtsByCustomer(Branch $shop, ?string $from, ?string $to): array
    {
        $debts = $this->debts->openDebtsForBranch((string) $shop->id, $from, $to);
        if ($debts === []) {
            return [];
        }

        $anchors = $this->orderAnchors->anchorsForBranch(
            (string) $shop->id,
            array_values(array_unique(array_map(static fn (OpenAccountDebt $d): string => $d->orderId, $debts))),
        );

        $byCustomer = [];
        foreach ($debts as $debt) {
            $customerId = $anchors[$debt->orderId]->customerId ?? null;
            if ($customerId === null) {
                continue;
            }
            $byCustomer[$customerId][] = $debt;
        }

        return $byCustomer;
    }

    /**
     * Mã đơn của từng khoản nợ — chỉ `show()` cần, nên tra riêng chứ không nhét
     * vào {@see openDebtsByCustomer()}.
     *
     * @param  list<OpenAccountDebt>  $debts
     * @return array<string, ?string> khoá là id đơn
     */
    private function orderCodesFor(Branch $shop, array $debts): array
    {
        $anchors = $this->orderAnchors->anchorsForBranch(
            (string) $shop->id,
            array_values(array_unique(array_map(static fn (OpenAccountDebt $d): string => $d->orderId, $debts))),
        );

        return array_map(static fn ($anchor): ?string => $anchor->orderCode, $anchors);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/debts/{customer}',
        summary: 'List one customer\'s individual open debts',
        description: 'The rows the grouped total is built from: one entry per open on_account payment, with the order it sits on and the payment id a settlement must reference.',
        tags: ['Shop', 'Debts'],
        security: [['bearer_token' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Individual open debts for the customer.'),
        ],
    )]
    /**
     * One row per open debt, so the POS can actually settle one.
     *
     * `index()` answers "who owes, how much in total" — which is all a manager
     * needs, and precisely not enough to collect: settling posts a payment with
     * `metadata.settles_payment_id`, and that id exists nowhere in the grouped
     * response. The POS "Tra cứu nợ" dialog could therefore list debtors and
     * then do nothing about them.
     *
     * Two amounts are returned per debt and the difference matters:
     *
     *   - `amount`     the original on_account payment. A settlement must equal
     *                  this EXACTLY — OrderPaymentStoreRequest compares against
     *                  `orig->amount` and rejects `settles_amount_mismatch`
     *                  otherwise, because a debt is one row and the link is
     *                  one-shot (there is no partial-settlement model).
     *   - `net_amount` the same debt after any refunds against it. This is what
     *                  the customer actually still owes, and what index() sums.
     *
     * They differ only for a PARTIALLY refunded debt, and in that state the
     * debt cannot be settled through this path at all: paying the net is
     * rejected by the amount guard, and paying the original over-collects.
     * `is_settleable` says so per row rather than letting the cashier find out
     * from a 422 with the customer standing there.
     */
    public function show(Request $request, string $customer): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $debts = $this->openDebtsByCustomer($shop, null, null)[$customer] ?? [];
        $orderCodes = $debts === [] ? [] : $this->orderCodesFor($shop, $debts);

        $data = array_map(fn (OpenAccountDebt $debt): array => [
            'payment_id' => $debt->paymentId,
            'order_id' => $debt->orderId,
            'order_code' => $orderCodes[$debt->orderId] ?? null,
            'amount' => (string) $debt->amount,
            'net_amount' => (string) $debt->netAmount,
            'is_settleable' => $debt->isSettleable(),
            'created_at' => $debt->createdAt,
            'note' => $debt->note,
        ], $debts);

        return response()->json([
            'data' => $data,
            'customer_id' => $customer,
            'shop_slug' => $shop->slug,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/pos/debts/part-paid',
        summary: 'Orders left part-paid, grouped by customer',
        description: 'Orders in `paying` status whose paid_amount is below total_amount. NOT on-account debt: no debt was recorded, the order was simply never settled. Exposed for the POS lookup only — the grouped /debts total that admin reports read is deliberately unchanged.',
        tags: ['Shop', 'Debts'],
        security: [['bearer_token' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Part-paid orders grouped by customer.'),
        ],
    )]
    /**
     * Money the shop is owed that NO debt report could see.
     *
     * A customer who pays ¥10 of a ¥1,265 order and leaves owes ¥1,255 — but
     * nothing was charged to their account, so `index()` cannot show it: that
     * query keys on `payment_method.type = 'on_account'` and there is no such
     * payment. The balance surfaced in exactly one place, the payment dialog's
     * banner, and only when that same customer was served again. A manager
     * looking at 公 debts never saw it at all.
     *
     * Deliberately a SEPARATE endpoint rather than a widening of `index()`:
     *
     *   - `index()` is what admin-web's "Công nợ khách hàng" panel sums. Folding
     *     these rows in would silently change a manager-facing money figure.
     *   - The two are different obligations. An on-account debt was granted on
     *     purpose and is collectible on its own terms; a part-paid order is an
     *     order nobody finished. Presenting them as one number would make it
     *     impossible to tell "we extended ¥X of credit" from "¥X walked out".
     *
     * NO time filter, on purpose. An order being served right now is also
     * `paying` with paid < total, so any cutoff would be a guess about when a
     * customer has "left" — and this repo has already paid for one guess like
     * that (a default read as a declaration). Instead every row carries its
     * order code and timestamps, and the caller shows them: a cashier reading
     * the list can tell a table they are serving from one that walked out, and
     * a rule in here could not.
     *
     * Only orders with a customer are grouped — a lookup is BY customer, and a
     * walk-in row has nobody to attribute or collect from. At the time of
     * writing this branch had zero such orders; if that changes they need their
     * own report, not a null group in this one.
     */
    public function partPaid(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        // #1992 — vị ngữ "trả chưa đủ" KHÔNG còn ở đây. Hai câu `DB::table` cũ
        // (một để gom nhóm, một để lấy chi tiết) đã chép lại đúng định nghĩa mà
        // `CustomerOutstandingOrderService` giữ; giờ cả hai đường đọc chung
        // `CustomerOrder::partPaid()` qua cổng này, và một truy vấn là đủ cho cả
        // hai tầng của báo cáo.
        $orders = $this->partPaidOrders->forBranch((string) $shop->id);

        /** @var array<string, list<PartPaidOrder>> $byCustomer */
        $byCustomer = [];
        foreach ($orders as $order) {
            $byCustomer[$order->customerId][] = $order;
        }

        $contacts = $this->customers->entriesByIds(array_map(strval(...), array_keys($byCustomer)));

        $data = [];
        foreach ($byCustomer as $customerId => $customerOrders) {
            $openedAt = array_values(array_filter(
                array_map(static fn (PartPaidOrder $o): ?string => $o->openedAt, $customerOrders),
            ));
            $contact = $contacts[$customerId] ?? null;

            $data[] = [
                'customer_id' => $customerId,
                'customer_name' => $contact?->displayName,
                'customer_phone' => $contact?->phone,
                'customer_tax_code' => $contact?->taxCode,
                'order_count' => count($customerOrders),
                'total_unpaid' => number_format(
                    array_sum(array_map(static fn (PartPaidOrder $o): float => $o->unpaidAmount, $customerOrders)),
                    2,
                    '.',
                    '',
                ),
                'oldest_at' => $openedAt === [] ? null : min($openedAt),
                'latest_at' => $openedAt === [] ? null : max($openedAt),
                // Chi tiết từng đơn đi kèm, để chọn một khách không phải gọi
                // thêm lượt nữa. Có trần theo chính phép gom nhóm: một quán có
                // ít đơn dở dang, và một khách còn ít hơn.
                'orders' => array_map(static fn (PartPaidOrder $o): array => [
                    'order_id' => $o->orderId,
                    'order_code' => $o->orderCode,
                    // Hình dạng chuỗi giữ NGUYÊN từng trường như payload cũ, kể
                    // cả chỗ không nhất quán (`unpaid_amount` không có phần thập
                    // phân đệm). Chuẩn hoá nó là một quyết định riêng, không đi
                    // ké một PR gom định nghĩa.
                    'total_amount' => number_format($o->totalAmount, 2, '.', ''),
                    'paid_amount' => number_format($o->paidAmount, 2, '.', ''),
                    'unpaid_amount' => (string) $o->unpaidAmount,
                    'opened_at' => $o->openedAt,
                ], $customerOrders),
            ];
        }

        // Nợ to nhất lên trước — giữ nguyên `orderByDesc('total_unpaid')` cũ.
        usort($data, static fn (array $a, array $b): int => (float) $b['total_unpaid'] <=> (float) $a['total_unpaid']);

        return response()->json([
            'data' => $data,
            'shop_slug' => $shop->slug,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

}
