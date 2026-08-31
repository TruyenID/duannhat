<?php

namespace App\Services\Inventory;

use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Omnify\Enums\StockTransactionStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Omnify\Enums\VoidStockEffectEnum;
use App\Services\Inventory\Contracts\VoidReasonStockEffect;
use App\Services\Order\Contracts\BranchStockDeductionTiming;
use App\Services\Order\Contracts\OrderLineStockSnapshot;
use App\Services\Order\Contracts\OrderStockContext;
use App\Services\Order\Contracts\OrderStockContextReads;
use App\Services\Order\Contracts\OrderStockLineReads;
use App\Services\Order\Contracts\OrderStockMarker;
use App\Services\Product\Contracts\RecipeSnapshot;
use App\Services\Product\Contracts\SkuDirectory;
use App\Services\Product\Contracts\SkuSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * plan-051 (#1150) — per-LINE stock deduction with an idempotent marker.
 *
 * Extracted from OrderClosingService phase 5 so the deduction can fire at
 * three per-shop timings (`stock_deduction_timing`):
 *
 *   - `on_close`   (default) — {@see sweepUndeductedLinesAtClose} runs from
 *     OrderClosingService::close() phase 5 and deducts every line that has no
 *     marker yet. When NO line was pre-deducted (the default path) the emitted
 *     stock transactions are BYTE-IDENTICAL to the legacy phase-5 shape:
 *     one `sales` stock_out for track_stock SKUs + one aggregated
 *     `sales_material_consumption` stock_out + the made_to_order genealogy
 *     preview, with the per-ORDER marker (customer_orders.stock_out_transaction_id)
 *     stamped exactly as before.
 *   - `on_preparing` — {@see deductLine} fires from the item-status funnel
 *     when a line REACHES preparing: it transitions THROUGH the trigger
 *     (pending → preparing/ready/served) OR it is BORN at/past it (no-KDS
 *     shops with default_order_item_status = preparing/served — those lines
 *     never see a transition).
 *   - `on_add` — {@see deductLine} fires right after line persistence in the
 *     add funnels; a qty Revise on an already-deducted pending line goes
 *     through {@see adjustDeductedLineQuantity} (delta deduct / partial
 *     compensation).
 *
 * Idempotency = the per-line marker (customer_order_items.stock_deducted_at +
 * stock_out_transaction_id), stamped IN THE SAME DB TRANSACTION as the stock
 * writes. A line with the marker is skipped by every subsequent hook AND by
 * the close sweep, which is what makes a mid-day timing flip safe: deducted
 * lines never deduct again, undeducted lines are always swept at close.
 *
 * Void compensation (#1149 — README truth table) lives in
 * {@see compensateVoid}: not-deducted → nothing; deducted+restock →
 * adjustment_in reversing the line's recorded deduction (original lots
 * preserved, batch-cancel pattern) referencing the original transaction;
 * deducted+waste → no compensation (material was truly consumed);
 * deducted+none/unknown → no compensation + warning log.
 *
 * Item-marker writes are routed through the published `OrderStockMarker`
 * port (#1595), which delegates to the plan-047 order-aggregate mutation
 * boundary; this service itself only
 * writes stock transactions via StockTransactionService.
 *
 * ## Ranh giới module (#962 / #1731) — ĐÃ TRẢ HẾT
 *
 * Class này không còn import một model nào của Ordering hay Catalog. Bốn cạnh
 * lần lượt đi qua cổng:
 *
 * | cạnh cũ            | cổng thay thế                                  | ở |
 * |--------------------|------------------------------------------------|---|
 * | `ShopOrderSetting` | {@see BranchStockDeductionTiming}              | #962 |
 * | `VoidReason`       | {@see VoidReasonStockEffect}                   | #962 |
 * | `CustomerOrder`    | {@see OrderStockContext}                       | #1605 |
 * | `CustomerOrderItem`| {@see OrderStockLineReads} + {@see OrderStockMarker} | **#1731** |
 * | `ProductSku`, `Recipe` | {@see SkuDirectory} → {@see SkuSnapshot}   | **#1731** |
 *
 * ## Vì sao #1731 phải làm CẢ HAI nửa cùng lúc
 *
 * #1567 đã cho Catalog trả `recipe` theo SKU, nhưng một mình nó không gỡ được
 * cạnh nào: động cơ này còn `lockForUpdate()` dòng đơn **trong cùng transaction**
 * với việc ghi kho, nên vẫn phải giữ model để khoá. Và ngược lại, một ảnh chụp
 * dòng đơn một mình chỉ DỜI cạnh sang Catalog. Hai nửa là:
 *
 *   1. `SkuSnapshot` mang `inventoryMode` — trường quyết định dòng có sinh phiếu
 *      xuất kho SKU hay không;
 *   2. {@see OrderStockLineReads} khoá được, và khoá **đi xuyên qua cổng** vì
 *      transaction là trạng thái của kết nối, không phải của class phát lệnh.
 *
 * ## Đây KHÔNG phải bỏ type-hint cho deptrac hết thấy
 *
 * Cách rẻ tiền là đổi `CustomerOrderItem $item` thành `$item` — deptrac xanh
 * trong khi runtime vẫn đọc y nguyên model. Đó là **gian lận phép đo** và #962
 * gọi tên nó. Ở đây runtime cũng đổi: dòng đơn tới dưới dạng
 * {@see OrderLineStockSnapshot} bất biến, danh mục tới dưới dạng
 * {@see SkuSnapshot}, và số truy vấn của đường trừ kho **giảm** (≈6 → 5) vì cả
 * lô SKU tra một lượt thay vì eager-load hai cây quan hệ.
 */
class StockDeductionService
{
    /**
     * Per-call state — last material consumption transaction id, used by the
     * close sweep's genealogy step as a fallback anchor when no Phase 1 SKU
     * stock_out was emitted (every SKU was made_to_order). Mirrors the legacy
     * OrderClosingService field; reset at the top of each deduction call.
     */
    private ?string $lastMaterialConsumptionTransactionId = null;

    public function __construct(
        private StockTransactionService $stockTransactionService,
        private GenealogyLinkService $genealogyLinkService,
    ) {}

    private function orderMarker(): OrderStockMarker
    {
        return app(OrderStockMarker::class);
    }

    /**
     * #962 — cấu hình thời điểm trừ kho của chi nhánh, do Ordering hiện thực.
     *
     * Phân giải LƯỜI qua container theo đúng khuôn của {@see orderMarker} ngay
     * trên: service này được dựng trong nhiều đường (container, test gọi
     * `app()`), và thêm tham số constructor cho một cổng chỉ dùng ở MỘT method
     * bắt mọi chỗ dựng nó phải biết về cổng đó.
     */
    private function branchTiming(): BranchStockDeductionTiming
    {
        return app(BranchStockDeductionTiming::class);
    }

    /**
     * #1605 — sáu trường vô hướng của đơn, do Ordering công bố.
     *
     * Phân giải LƯỜI qua container theo đúng khuôn của {@see orderMarker} và
     * {@see branchTiming} ngay trên — cùng lý do đã ghi ở đó.
     */
    private function orderContext(): OrderStockContextReads
    {
        return app(OrderStockContextReads::class);
    }

    /**
     * #1731 — dòng đơn (kèm quyền khoá), do Ordering công bố.
     *
     * Phân giải LƯỜI theo đúng khuôn ba cổng ngay trên — cùng lý do đã ghi ở đó.
     */
    private function orderLines(): OrderStockLineReads
    {
        return app(OrderStockLineReads::class);
    }

    /**
     * #1731 — danh mục SKU (kèm `inventoryMode` + công thức), do Catalog công bố.
     */
    private function skus(): SkuDirectory
    {
        return app(SkuDirectory::class);
    }

    /**
     * Tra một lượt mọi SKU mà lô dòng đơn này chạm tới — cả SKU của dòng lẫn SKU
     * của topping.
     *
     * MỘT truy vấn cho cả lô, thay cho hai cây eager-load của bản cũ
     * (`productSku.recipe` + `orderItemToppings.productSku.recipe`).
     *
     * **KHÔNG giới hạn tổ chức** — dùng `byIds()` chứ không phải
     * `byIdsForOrganization()`. Bản đầu của #1731 đã lọc theo tổ chức của đơn vì
     * nghe có vẻ chặt hơn, và 25 test đỏ ngay: SKU không khớp tổ chức thì lượt
     * trừ kho **im lặng không trừ gì cả**. Đó không phải test cẩu thả mà là một
     * chế độ hỏng mới — bán hàng xong, tồn kho không nhúc nhích, và chỉ lộ ra
     * lúc kiểm kê. Phạm vi ở đây không mua thêm cách ly nào: id tới từ dòng của
     * một đơn ĐÃ thuộc tổ chức đó, không phải từ dữ liệu người dùng nhập. Lý do
     * đầy đủ ở docblock của `SkuDirectory::byIds()`.
     *
     * @param  list<OrderLineStockSnapshot>  $lines
     * @return array<string, SkuSnapshot>
     */
    private function skuSnapshotsFor(array $lines, string $organizationId): array
    {
        $ids = [];
        foreach ($lines as $line) {
            if ($line->productSkuId !== null) {
                $ids[$line->productSkuId] = true;
            }
            foreach ($line->toppings as $topping) {
                if ($topping->productSkuId !== null) {
                    $ids[$topping->productSkuId] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return $this->skus()->byIds(array_keys($ids));
    }

    // =========================================================================
    //  Timing resolution
    // =========================================================================

    /**
     * The branch's effective stock-deduction timing. Missing row / unreadable
     * value degrades to `on_close` (the legacy behaviour).
     */
    public function timingForBranch(string $branchId): StockDeductionTimingEnum
    {
        // #962 — cột `shop_order_settings.stock_deduction_timing` thuộc Ordering,
        // nên chỉ CÂU TRUY VẤN đi qua cổng. Ba ca dẫn về `on_close` — chưa cấu
        // hình, truy vấn không đọc được, giá trị lạ — vẫn được quyết ở đây, vì
        // trừ muộn nhất là lựa chọn an toàn cho tồn kho và tồn kho là việc của
        // Inventory.
        $raw = $this->branchTiming()->rawTimingFor($branchId);

        if ($raw === null) {
            return StockDeductionTimingEnum::OnClose;
        }

        return StockDeductionTimingEnum::tryFrom($raw)
            ?? StockDeductionTimingEnum::OnClose;
    }

    /**
     * DESIGN §2.2 born-at-status rule: "reached the trigger" = the line is at
     * or past `preparing` (preparing / ready / served). `voided` never counts.
     */
    public static function statusHasReachedPreparing(string $status): bool
    {
        return in_array($status, [
            OrderItemStatusEnum::Preparing->value,
            OrderItemStatusEnum::Ready->value,
            OrderItemStatusEnum::Served->value,
        ], true);
    }

    // =========================================================================
    //  T2.1 — per-line deduction (hooks) + close sweep
    // =========================================================================

    /**
     * Deduct ONE order line now (on_add / on_preparing hooks). Idempotent via
     * the per-line marker — a line already deducted (or voided / a refund
     * line) is a silent no-op. The stock writes and the marker stamp share one
     * DB transaction, so a crash can never leave a deducted-but-unmarked line.
     *
     * @param  string  $cause  hook name for the audit trail (on_add | on_preparing | ...)
     * @param  \DateTimeInterface|null  $occurredAt  the REAL event instant when the
     *                                               caller knows it (#1091 — e.g. an offline order's signed sale time);
     *                                               null → now(). Stamped into stock_deducted_at.
     */
    public function deductLine(string $orderItemId, string $cause, ?\DateTimeInterface $occurredAt = null): void
    {
        // #1605 — chỉ lấy khoá ngoại, không nạp cả dòng: thân method chỉ cần
        // biết đơn nào, còn chính dòng đó sẽ được đọc lại DƯỚI KHOÁ ngay bên
        // dưới (bản cũ cũng vậy, chỉ khác là nó nhận sẵn model từ adapter).
        $orderId = $this->orderLines()->orderIdOf($orderItemId);
        if ($orderId === null) {
            return;
        }

        $order = $this->orderContext()->find($orderId);
        if ($order === null) {
            return;
        }

        $this->lastMaterialConsumptionTransactionId = null;

        DB::transaction(function () use ($order, $orderItemId, $cause, $occurredAt) {
            // Re-fetch under lock and re-check the marker so two concurrent
            // hooks (double bump, replayed sync) serialize on the row and the
            // loser sees the winner's marker. #1731 — ba điều kiện đó nằm TRONG
            // câu khoá của cổng, không phải kiểm sau khi đọc.
            $locked = $this->orderLines()->lockUndeductedLine($orderItemId);

            if ($locked === null) {
                return;
            }

            $this->deductItems(
                order: $order,
                lines: [$locked],
                tag: $this->lineTag($orderItemId),
                stampOrderMarker: false,
                occurredAt: $occurredAt,
                cause: $cause,
            );
        });
    }

    /**
     * OrderClosingService phase 5 — deduct every line that carries NO per-line
     * marker yet. Runs inside close()'s ring-fenced savepoint. Keeps the
     * legacy per-order marker behaviour (customer_orders.stock_out_transaction_id
     * stamped when a Phase 1 SKU stock_out is emitted) for whole-order
     * idempotency exactly as before plan-051.
     *
     * #1605 — nhận **id** (bản #1666 `…ByOrderId` gộp vào đây khi cạnh
     * `CustomerOrder` được trả): đơn không tồn tại là no-op im lặng, cùng quyết
     * định với mọi method khác của cổng — xem `EloquentOrderLineStockDeduction`.
     */
    public function sweepUndeductedLinesAtClose(string $orderId): void
    {
        $order = $this->orderContext()->find($orderId);

        if ($order === null) {
            return;
        }

        $this->lastMaterialConsumptionTransactionId = null;

        // Same query as the legacy phase 5 (voided lines skipped, refund lines
        // never generate a stock-out) + the plan-051 marker filter. On the
        // default on_close path nothing is pre-marked, so the set — and every
        // transaction emitted from it — is identical to the legacy shape.
        //
        // #1605 — `$order->items()` (hasMany trên `customer_order_id`, không có
        // scope nào) viết thẳng thành điều kiện tương đương; ràng buộc và thứ tự
        // giữ nguyên từng chữ. #1731 — câu đó nay sống trong
        // `EloquentOrderStockLineReads::undeductedLinesOfOrder()`.
        $lines = $this->orderLines()->undeductedLinesOfOrder($order->id);

        if ($lines === []) {
            return;
        }

        $this->deductItems(
            order: $order,
            lines: $lines,
            tag: null,
            stampOrderMarker: true,
            occurredAt: null,
            cause: 'on_close',
        );
    }

    /**
     * The shared deduction engine — the legacy OrderClosingService phase-5
     * body, parameterized by line subset.
     *
     * @param  list<OrderLineStockSnapshot>  $lines  non-voided, non-refund lines to deduct
     * @param  string|null  $tag  per-line note tag (hook mode) — null in sweep
     *                            mode so the close-path notes stay byte-identical to legacy
     * @param  array<string, float>  $quantityOverride  itemId => qty (delta mode)
     * @param  bool  $stampLineMarkers  false in delta mode (line already marked)
     */
    private function deductItems(
        OrderStockContext $order,
        array $lines,
        ?string $tag,
        bool $stampOrderMarker,
        ?\DateTimeInterface $occurredAt,
        string $cause,
        array $quantityOverride = [],
        bool $stampLineMarkers = true,
    ): void {
        $qtyOf = static fn (OrderLineStockSnapshot $line): float => (float) ($quantityOverride[$line->id] ?? $line->quantity);
        $noteSuffix = $tag !== null ? ' '.$tag : '';

        $skus = $this->skuSnapshotsFor($lines, $order->organizationId);

        // created_by_id is nullable on customer_orders but NOT NULL on
        // stock_transactions — fall back to the authenticated user, then
        // the customer id, so the DB constraint is never violated.
        $createdById = $order->createdById
            ?? auth()->id()
            ?? $order->customerId;

        $warehouseId = $this->getDefaultWarehouse($order->branchId, $order->organizationId);

        // Phase 1 — SKU stock-out gated by inventory_mode.
        //
        // #1731 — `inventoryMode` nay tới từ ảnh chụp danh mục. SKU không tra
        // được (đã xoá, hoặc thuộc tổ chức khác) ⇒ KHÔNG track_stock, đúng như
        // bản cũ khi `$item->productSku` là null.
        $trackStockItems = array_values(array_filter(
            $lines,
            fn (OrderLineStockSnapshot $line): bool => $line->productSkuId !== null
                && ($skus[$line->productSkuId] ?? null)?->tracksStock() === true,
        ));
        $isTrackStock = array_fill_keys(array_map(static fn ($l) => $l->id, $trackStockItems), true);

        $stockTransaction = null;
        if ($trackStockItems !== []) {
            $stockTransaction = $this->stockTransactionService->create([
                'type' => 'stock_out',
                'sub_type' => 'sales',
                'warehouse_id' => $warehouseId,
                'reference_type' => 'customer_order',
                'reference_id' => $order->id,
                'organization_id' => $order->organizationId,
                'created_by_id' => $createdById,
                'note' => "Auto stock-out for order {$order->orderCode}".$noteSuffix,
                'items' => array_map(fn (OrderLineStockSnapshot $line) => [
                    'product_sku_id' => $line->productSkuId,
                    'quantity' => $qtyOf($line),
                    'unit_price' => $line->unitPrice,
                ], $trackStockItems),
            ]);

            // Plan-024 T0.6 fix — submit so the warehouse's
            // auto_approve_stock_out flag drives completeTransaction
            // and the StockLevel decrement actually happens.
            $stockTransaction = $this->stockTransactionService->submit($stockTransaction);

            if ($stampOrderMarker) {
                $this->orderMarker()->stampOrderStockOutTransaction((string) $order->id, (string) $stockTransaction->id);
            }
        }

        // Phase 2 — Recipe → Material deduction for track_stock SKUs
        // (+ topping materials of every line in the subset).
        $this->emitMaterialConsumptionTransaction(
            order: $order,
            trackStockItems: $trackStockItems,
            allItems: $lines,
            skus: $skus,
            warehouseId: $warehouseId,
            createdById: (string) $createdById,
            noteSuffix: $noteSuffix,
            qtyOf: $qtyOf,
        );

        // plan-040 C8: made_to_order SKUs do NOT emit a real material
        // consumption transaction at sale time (their material was consumed
        // upstream during MaterialBatch production) — their sales edges come
        // from the best-effort FEFO preview, scoped to the made_to_order
        // subset only so track_stock items are never double-counted.
        //
        // Delta mode skips the preview: the line's edges were recorded by its
        // original deduction; re-previewing a qty delta would double-count.
        if ($quantityOverride === []) {
            $madeToOrderItems = array_values(array_filter(
                $lines,
                static fn (OrderLineStockSnapshot $line): bool => ! isset($isTrackStock[$line->id]),
            ));
            if ($madeToOrderItems !== []) {
                $anchorId = $stockTransaction?->id
                    ?? $this->lastMaterialConsumptionTransactionId
                    ?? $order->id;
                $this->recordSalesGenealogyFor(
                    $order,
                    (string) $anchorId,
                    $madeToOrderItems,
                    $skus,
                );
            }
        }

        // plan-051 — stamp the per-line marker in the SAME transaction as the
        // stock writes (callers wrap us in DB::transaction). track_stock lines
        // anchor on the Phase 1 sales tx; everything else anchors on the
        // material consumption tx when one was emitted.
        if ($stampLineMarkers) {
            $deductedAt = $occurredAt ?? now();
            foreach ($lines as $line) {
                $anchorTxId = isset($isTrackStock[$line->id])
                    ? ($stockTransaction?->id !== null ? (string) $stockTransaction->id : null)
                    : ($this->lastMaterialConsumptionTransactionId ?? ($stockTransaction?->id !== null ? (string) $stockTransaction->id : null));
                $this->orderMarker()->markLineDeducted($line->id, $deductedAt, $anchorTxId);
                $this->orderMarker()->recordLineStockAudit($line->id, 'stock_deducted', [
                    'cause' => $cause,
                    'stock_out_transaction_id' => $anchorTxId,
                ]);
            }
        }
    }

    // =========================================================================
    //  T2.2 — qty delta on an already-deducted pending line (on_add Revise)
    // =========================================================================

    /**
     * A pending line that was ALREADY deducted (on_add timing) had its qty
     * revised: deduct the extra units, or compensate the removed ones.
     * No-op when the line carries no marker (the close sweep will price the
     * final quantity) or the qty didn't actually change.
     */
    public function adjustDeductedLineQuantity(string $orderItemId, float $previousQuantity): void
    {
        $item = $this->orderLines()->find($orderItemId);
        if ($item === null) {
            return;
        }

        if (! $item->isDeducted()) {
            return;
        }

        $newQuantity = $item->quantity;
        $delta = $newQuantity - $previousQuantity;
        if (abs($delta) < 1e-9) {
            return;
        }

        $order = $this->orderContext()->find($item->orderId);
        if ($order === null) {
            return;
        }

        if ($delta > 0) {
            // Extra deduction scaled to the delta units, same transaction
            // shapes as the original per-line deduction.
            $this->lastMaterialConsumptionTransactionId = null;
            DB::transaction(function () use ($order, $item, $delta) {
                $fresh = $this->orderLines()->lockLine($item->id);
                if ($fresh === null) {
                    return;
                }
                $this->deductItems(
                    order: $order,
                    lines: [$fresh],
                    tag: $this->lineTag($item->id),
                    stampOrderMarker: false,
                    occurredAt: null,
                    cause: 'qty_delta',
                    quantityOverride: [$item->id => $delta],
                    stampLineMarkers: false,
                );
            });

            return;
        }

        // Partial compensation — reverse |delta|/previousQuantity of the
        // line's recorded, still-outstanding deduction.
        $this->emitCompensation(
            item: $item,
            order: $order,
            fraction: abs($delta) / max($previousQuantity, 1e-9),
            noteReason: sprintf('qty revised %s -> %s', rtrim(rtrim(number_format($previousQuantity, 4, '.', ''), '0'), '.'), rtrim(rtrim(number_format($newQuantity, 4, '.', ''), '0'), '.')),
        );
    }

    // =========================================================================
    //  T2.4 — void compensation (README truth table)
    // =========================================================================

    /**
     * Stock effect of voiding a line, per the plan-051 truth table:
     *
     * | line state    | stock_effect      | action                              |
     * |---------------|-------------------|-------------------------------------|
     * | not deducted  | any               | nothing (line skipped from deduction)|
     * | deducted      | restock           | adjustment_in reversing the recorded |
     * |               |                   | deduction, referencing the original  |
     * | deducted      | waste             | NO compensation — material consumed; |
     * |               |                   | logged for the waste report          |
     * | deducted      | none / unknown    | NO compensation + warning log        |
     *
     * `$reason === null` covers the legacy free-text path (old workstations,
     * junk-less pending voids): a deducted line voided without a structured
     * reason is NOT compensated — restocking blindly is worse than a manual
     * adjustment — and ops sees a warning. #962: `void_reasons` is Ordering's
     * table, so the reason arrives as the published {@see VoidReasonStockEffect}
     * snapshot instead of the Eloquent model — the three fields below are all
     * this method ever read off it.
     */
    public function compensateVoid(string $orderItemId, ?VoidReasonStockEffect $reason): void
    {
        $item = $this->orderLines()->find($orderItemId);
        if ($item === null) {
            return;
        }

        if (! $item->isDeducted()) {
            // Not deducted (any timing) — the voided line is simply skipped by
            // the deduction sweep, the pre-plan-051 #1148 behaviour.
            return;
        }

        $order = $this->orderContext()->find($item->orderId);
        if ($order === null) {
            return;
        }

        $effectValue = $reason?->stockEffect;

        if ($effectValue === VoidStockEffectEnum::Restock->value) {
            $this->emitCompensation(
                item: $item,
                order: $order,
                fraction: 1.0,
                noteReason: 'void restock — reason: '.($reason?->label ?? (string) $reason?->id),
                voidReason: $reason,
            );

            return;
        }

        if ($effectValue === VoidStockEffectEnum::Waste->value) {
            // No compensation — the material was genuinely consumed. Recorded
            // here (structured log + item audit) so waste can be reported per
            // reason.
            //
            // TODO(plan-051 P5): StockTransactionService has no tagging column
            // on completed transactions (no metadata / waste_reason_id), so the
            // original stock_out cannot be tagged in-place yet. The waste
            // report should aggregate from the `stock_waste_recorded` audit
            // rows (void_reason_id + original transaction id) until a proper
            // waste ledger lands.
            Log::info('plan-051: void with waste effect — stock stays consumed', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'void_reason_id' => $reason?->id,
                'stock_out_transaction_id' => $item->stockOutTransactionId,
            ]);
            $this->orderMarker()->recordLineStockAudit($item->id, 'stock_waste_recorded', [
                'void_reason_id' => $reason?->id,
                'stock_out_transaction_id' => $item->stockOutTransactionId,
            ]);

            return;
        }

        // `none` (comp for the customer — the dish was still served) and
        // unknown (legacy text reason / old workstation): never compensate.
        Log::warning('plan-051: voided a deducted line without a restock effect — no stock compensation applied', [
            'order_id' => $order->id,
            'item_id' => $item->id,
            'void_reason_id' => $reason?->id,
            'stock_effect' => $effectValue,
            'stock_out_transaction_id' => $item->stockOutTransactionId,
        ]);
    }

    /**
     * Reverse (a fraction of) the line's recorded, still-outstanding
     * deduction via a single `stock_in` / `adjustment_in` transaction.
     *
     * "Recorded" = the per-line tagged stock transactions this service
     * emitted for the line (deduction + qty deltas − prior compensations),
     * netted per (sku, material, lot, unit) — so a void-after-revise never
     * double-restocks what a partial compensation already returned, and the
     * FEFO-split lot rows restock into the SAME lots the deduction drained
     * (MaterialBatchService::cancel precedent). Runs through
     * StockTransactionService so stock_levels + stock_movements stay in sync.
     */
    private function emitCompensation(
        OrderLineStockSnapshot $item,
        OrderStockContext $order,
        float $fraction,
        string $noteReason,
        ?VoidReasonStockEffect $voidReason = null,
    ): void {
        $rows = $this->outstandingDeductionRows($order->id, $item->id);

        if ($rows === []) {
            // Marked-deducted but nothing attributable was recorded (e.g. a
            // made_to_order line with no topping materials, or a close-sweep
            // line — those belong to closed orders and cannot be voided).
            Log::info('plan-051: compensation requested but the line has no outstanding recorded deduction', [
                'order_id' => $order->id,
                'item_id' => $item->id,
                'stock_out_transaction_id' => $item->stockOutTransactionId,
            ]);

            return;
        }

        $fraction = max(0.0, min(1.0, $fraction));
        if ($fraction <= 0.0) {
            return;
        }

        $items = [];
        foreach ($rows as $row) {
            $qty = $row['quantity'] * $fraction;
            if ($qty <= 1e-9) {
                continue;
            }
            $items[] = [
                'product_sku_id' => $row['product_sku_id'],
                'material_id' => $row['material_id'],
                'material_lot_id' => $row['material_lot_id'],
                'quantity' => $qty,
                'unit' => $row['unit'],
            ];
        }

        if ($items === []) {
            return;
        }

        $createdById = $order->createdById ?? auth()->id() ?? $order->customerId;

        DB::transaction(function () use ($item, $order, $items, $noteReason, $createdById, $voidReason) {
            $reversal = $this->stockTransactionService->create([
                'organization_id' => $order->organizationId,
                'warehouse_id' => $this->getDefaultWarehouse($order->branchId, $order->organizationId),
                'type' => StockTransactionTypeEnum::StockIn->value,
                'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                'reference_type' => 'customer_order',
                'reference_id' => $order->id,
                'created_by_id' => $createdById,
                'note' => sprintf(
                    'plan-051 stock compensation for order %s (reverses %s) — %s %s',
                    $order->orderCode,
                    $item->stockOutTransactionId ?? 'n/a',
                    $noteReason,
                    $this->lineTag($item->id),
                ),
                'items' => $items,
            ]);
            $this->stockTransactionService->submit($reversal);

            // Append-only reversal genealogy edges for the lot-attributed rows
            // so recall blast radius nets the returned quantity out
            // (MaterialBatchService::cancel precedent).
            foreach ($items as $row) {
                if (empty($row['material_lot_id'])) {
                    continue;
                }
                $parent = MaterialLot::find($row['material_lot_id']);
                if ($parent === null) {
                    continue;
                }
                $this->genealogyLinkService->recordReversal(
                    parentLot: $parent,
                    childLot: null,
                    qtyConsumed: (float) $row['quantity'],
                    unit: $row['unit'],
                    sourceEventId: $item->id,
                );
            }

            $this->orderMarker()->recordLineStockAudit($item->id, 'stock_compensated', [
                'reversal_transaction_id' => $reversal->id,
                'void_reason_id' => $voidReason?->id,
                'original_transaction_id' => $item->stockOutTransactionId,
            ]);
        });
    }

    /**
     * #1205 — does this line still hold stock that was never returned?
     *
     * A read-only wrapper over {@see outstandingDeductionRows} so the repair
     * sweep can tell "already compensated" from "compensation was lost" without
     * duplicating the tag/status logic that decides it. The void path itself is
     * ring-fenced (an inventory failure keeps the void and only logs), so a
     * lost compensation is invisible until someone counts the shelf.
     *
     * #1731 — nhận **id**. Ghi chú #1605 ở đây từng lập luận ngược lại: người
     * gọi (`RepairVoidStockCompensation`) đang cầm sẵn dòng đơn, nên nhận model
     * tránh được một lượt nạp lại cho MỖI ứng viên, mà lúc đó đổi sang id cũng
     * không gỡ được cạnh nào — `CustomerOrderItem` vẫn còn ở class này vì cây
     * recipe/topping.
     *
     * Cả hai vế của lập luận đó nay đã đổi, và đo được:
     *
     *  - **Cạnh thì gỡ được.** Sau #1731 đây là chỗ CUỐI CÙNG nhắc tên model;
     *    giữ nó là giữ nguyên cả cạnh vì một method của lệnh bảo trì chạy hàng
     *    ngày.
     *  - **Cái "tiết kiệm" nhỏ hơn nó nghe.** Thân method này vốn đã chạy HAI
     *    truy vấn cho mỗi ứng viên (`orderContext()->find()` +
     *    `outstandingDeductionRows()`), nên lượt đọc thêm là +50% trên một lệnh
     *    nền quét các dòng huỷ trong 30 ngày — không phải trên đường bán hàng.
     */
    public function hasOutstandingDeduction(string $orderItemId): bool
    {
        $item = $this->orderLines()->find($orderItemId);
        if ($item === null || ! $item->isDeducted()) {
            return false;
        }

        $order = $this->orderContext()->find($item->orderId);
        if ($order === null) {
            return false;
        }

        return $this->outstandingDeductionRows($order->id, $item->id) !== [];
    }

    /**
     * Net still-outstanding deduction rows for a line: Σ(tagged completed
     * stock_out rows) − Σ(tagged completed stock_in rows), keyed by
     * (product_sku_id, material_id, material_lot_id, unit). Positive nets only.
     *
     * @return list<array{product_sku_id: ?string, material_id: ?string, material_lot_id: ?string, quantity: float, unit: ?string}>
     */
    private function outstandingDeductionRows(string $orderId, string $itemId): array
    {
        $tag = $this->lineTag($itemId);

        $transactions = StockTransaction::query()
            ->where('reference_type', 'customer_order')
            ->where('reference_id', $orderId)
            ->where('note', 'like', '%'.$tag.'%')
            ->with('items')
            ->get();

        $pending = $transactions->first(fn (StockTransaction $tx) => $this->statusValue($tx) !== StockTransactionStatusEnum::Completed->value);
        if ($pending !== null) {
            // A tagged transaction is still awaiting approval
            // (auto_approve=false warehouse) — its StockLevel writes haven't
            // landed, so netting it would compensate stock that never moved.
            Log::warning('plan-051: line has non-completed tagged stock transactions; they are excluded from compensation netting', [
                'order_id' => $orderId,
                'item_id' => $itemId,
                'transaction_id' => $pending->id,
            ]);
        }

        $net = [];
        foreach ($transactions as $tx) {
            if ($this->statusValue($tx) !== StockTransactionStatusEnum::Completed->value) {
                continue;
            }
            $sign = $tx->type === StockTransactionTypeEnum::StockIn ? -1.0 : 1.0;
            foreach ($tx->items as $row) {
                $key = implode('|', [
                    (string) ($row->product_sku_id ?? ''),
                    (string) ($row->material_id ?? ''),
                    (string) ($row->material_lot_id ?? ''),
                    (string) ($row->unit ?? ''),
                ]);
                if (! isset($net[$key])) {
                    $net[$key] = [
                        'product_sku_id' => $row->product_sku_id,
                        'material_id' => $row->material_id,
                        'material_lot_id' => $row->material_lot_id,
                        'quantity' => 0.0,
                        'unit' => $row->unit,
                    ];
                }
                $net[$key]['quantity'] += $sign * (float) $row->base_quantity;
            }
        }

        return array_values(array_filter($net, static fn (array $row) => $row['quantity'] > 1e-9));
    }

    private function statusValue(StockTransaction $tx): string
    {
        return $tx->status instanceof \BackedEnum ? $tx->status->value : (string) $tx->status;
    }

    /**
     * Deterministic per-line note tag. Stock transactions have no metadata
     * column, so the line attribution rides the note — uuid-keyed, so a LIKE
     * lookup is exact. Only hook-emitted (per-line) transactions carry it;
     * close-sweep transactions stay byte-identical to the legacy shape (their
     * lines belong to closed orders and are never voidable/compensable).
     */
    private function lineTag(string $itemId): string
    {
        return '[plan051:item='.$itemId.']';
    }

    // =========================================================================
    //  Ported phase-5 internals (byte-identical behaviour for the sweep)
    // =========================================================================

    /**
     * Resolve the branch's default warehouse for stock-out. Prefers the
     * first existing active warehouse scoped to the branch; creates a minimal
     * default row on the fly otherwise, so close-order never fails solely on
     * an infrastructure seed gap. (Moved verbatim from OrderClosingService.)
     *
     * #1605 — nhận hai id thay vì đơn: đó là TOÀN BỘ những gì thân method từng
     * đọc khỏi `CustomerOrder`. Kho là chuyện của chi nhánh, không phải của đơn.
     */
    public function getDefaultWarehouse(string $branchId, string $organizationId): string
    {
        $existing = Warehouse::where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        // #2532 — `code` là UNIQUE THEO TỔ CHỨC (`warehouses_organization_id_code_unique`,
        // BR-01 của `Warehouse.yaml`), KHÔNG theo chi nhánh.
        //
        // Bản cũ tra `firstOrCreate` theo `(branch_id, code='DEFAULT')`: chi
        // nhánh thứ hai của cùng org không thấy hàng của chi nhánh thứ nhất nên
        // rẽ sang INSERT, và insert đó vỡ unique → `1062 Duplicate entry
        // '{orgId}-DEFAULT'`. Đo trên production 2026-08-12: **34 đơn** của
        // 人形町店 đóng xong mà kho không trừ — mỗi lần một ERROR
        // `inventory.stock_drift`, tiền đã thu, sổ kho lệch tích luỹ cả ngày.
        // Nó im lặng đúng một ngày vì org chỉ vừa có chi nhánh thứ hai.
        //
        // Luật rút ra: **khoá tra phải TRÙNG khoá unique**. Mã sinh từ branch id
        // (bỏ gạch ngang: 8 + 32 = 40 ≤ 50 ký tự, chỉ chữ và số ⇒ thoả BR-01)
        // nên nó vừa unique theo chi nhánh vừa unique trong org, không phải cắt
        // ngắn và không còn ca đụng nào để xử.
        $code = 'DEFAULT-'.str_replace('-', '', $branchId);

        // Hàng đã XOÁ MỀM vẫn chiếm chỗ trong unique index — MySQL không biết
        // `deleted_at`. Bỏ qua nó rồi INSERT là tái hiện đúng 1062 ở trên dưới
        // một hình dạng khác, nên tra `withTrashed()` và khôi phục. Nói ra bằng
        // log vì đây là hàng ai đó đã cố ý xoá.
        $trashedOrExisting = Warehouse::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($trashedOrExisting !== null) {
            if ($trashedOrExisting->trashed()) {
                Log::warning('inventory.default_warehouse_restored', [
                    'warehouse_id' => $trashedOrExisting->id,
                    'branch_id' => $branchId,
                    'organization_id' => $organizationId,
                    'code' => $code,
                ]);
                $trashedOrExisting->restore();
            }

            return $trashedOrExisting->id;
        }

        // `createOrFirst` chứ không phải `create`: hai lượt đóng đơn cùng lúc
        // trên một chi nhánh chưa có kho đều trượt bước tra ở trên, và một
        // trong hai sẽ nhận 1062 nếu ghi thẳng.
        $warehouse = Warehouse::createOrFirst(
            [
                'organization_id' => $organizationId,
                'code' => $code,
            ],
            [
                'branch_id' => $branchId,
                'name' => 'Default',
                'type' => 'branch',
                'is_active' => true,
                'auto_approve_stock_in' => true,
                'auto_approve_stock_out' => true,
                // Honour the schema default (false): a lazily-created default
                // warehouse must NOT silently disable the strict-shortage
                // guard (plan-024).
                'allow_negative_sales' => false,
            ],
        );

        return $warehouse->id;
    }

    /**
     * Phase 2 — Recipe → Material deduction. Moved from OrderClosingService
     * (plan-024 / plan-040 M5+M7 semantics preserved), parameterized by line
     * subset + optional note tag + effective-qty resolver.
     *
     * @param  list<OrderLineStockSnapshot>  $trackStockItems  filtered by inventory_mode=track_stock
     * @param  list<OrderLineStockSnapshot>  $allItems  all lines in the subset (for topping materials, TH.3)
     * @param  array<string, SkuSnapshot>  $skus  ảnh chụp danh mục cho cả lô (#1731)
     * @param  \Closure(OrderLineStockSnapshot): float  $qtyOf
     */
    private function emitMaterialConsumptionTransaction(
        OrderStockContext $order,
        array $trackStockItems,
        array $allItems,
        array $skus,
        string $warehouseId,
        string $createdById,
        string $noteSuffix,
        \Closure $qtyOf,
    ): void {
        // Plan-024 follow-up — pre-sale StockLevel snapshot for the pre-made
        // double-deduct guard. The Phase 1 SKU stock-out submit ran moments
        // ago, so the current StockLevel already reflects post-sale state; we
        // reconstruct the pre-sale quantity per SKU so the recipe walk can
        // decide whether a ProductionBatch upstream already committed material.
        $postSaleStockBySku = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_sku_id', array_values(array_unique(array_filter(
                array_map(static fn (OrderLineStockSnapshot $l): ?string => $l->productSkuId, $trackStockItems)
            ))))
            ->get(['product_sku_id', 'quantity'])
            ->mapWithKeys(fn ($row) => [(string) $row->product_sku_id => (float) $row->quantity])
            ->all();

        $orderQtyBySku = [];
        foreach ($trackStockItems as $item) {
            $sid = (string) $item->productSkuId;
            $orderQtyBySku[$sid] = ($orderQtyBySku[$sid] ?? 0) + $qtyOf($item);
        }

        // Aggregate ingredient quantities across all track_stock items.
        // Key: material_id (string) -> ['qty' => float, 'unit' => ?string]
        $aggregated = [];

        foreach ($trackStockItems as $item) {
            // Skip recipe deduction when this SKU was pre-made (had on-hand
            // stock before the sale) — the upstream ProductionBatch already
            // committed material (plan-024 NOTES.md option 1).
            $skuId = (string) $item->productSkuId;
            if (! array_key_exists($skuId, $postSaleStockBySku)) {
                // No StockLevel row → never-stocked SKU. Recipe deduction
                // must run (Plan-024 G3 canonical path).
                $preSaleQty = 0.0;
            } else {
                $preSaleQty = $postSaleStockBySku[$skuId] + ($orderQtyBySku[$skuId] ?? 0);
            }
            if ($preSaleQty > 0) {
                Log::info('order-close: material deduction skipped: SKU was pre-made (production batch upstream)', [
                    'order_id' => $order->id,
                    'sku_id' => $item->productSkuId,
                    'pre_sale_qty' => $preSaleQty,
                    'sold_qty' => $item->quantity,
                ]);

                continue;
            }

            $recipe = $skus[$skuId]?->recipe ?? null;
            if (! $recipe) {
                Log::warning('order-close: material deduction skipped: SKU has track_stock but no recipe', [
                    'order_id' => $order->id,
                    'sku_id' => $item->productSkuId,
                ]);

                continue;
            }

            // plan-040 M7 (TH.4): sales-time consumption only deducts against
            // an APPROVED recipe.
            if (! $recipe->isApproved()) {
                Log::warning('order-close: material deduction skipped: recipe not approved', [
                    'order_id' => $order->id,
                    'sku_id' => $item->productSkuId,
                    'recipe_id' => $recipe->id,
                    'approval_status' => $recipe->approvalStatus->value,
                ]);

                continue;
            }

            $ingredients = $recipe->ingredients;
            if ($ingredients === []) {
                Log::warning('order-close: material deduction skipped: recipe has no ingredients', [
                    'order_id' => $order->id,
                    'sku_id' => $item->productSkuId,
                    'recipe_id' => $recipe->id,
                ]);

                continue;
            }

            $itemQty = $qtyOf($item);
            $scale = $itemQty / max($this->outputQuantity($recipe), 1e-9);

            $this->aggregateIngredients($ingredients, $scale, $aggregated);
        }

        // plan-040 M5 (TH.3): deduct selected-topping materials — a topping is
        // always made fresh at order time, so it deducts regardless of the
        // parent SKU's inventory_mode or pre-made state.
        foreach ($allItems as $item) {
            $itemQty = $qtyOf($item);
            foreach ($item->toppings as $topping) {
                if ($topping->voided) {
                    continue;
                }

                $tRecipe = $topping->productSkuId === null
                    ? null
                    : ($skus[$topping->productSkuId] ?? null)?->recipe;
                if (! $tRecipe) {
                    continue;
                }

                // plan-040 M7 (TH.4): the approval gate applies to topping
                // recipes too — never bleed inventory off a draft recipe.
                if (! $tRecipe->isApproved()) {
                    Log::warning('order-close: topping material deduction skipped: recipe not approved', [
                        'order_id' => $order->id,
                        'topping_sku_id' => $topping->productSkuId,
                        'recipe_id' => $tRecipe->id,
                    ]);

                    continue;
                }

                $tIngredients = $tRecipe->ingredients;
                if ($tIngredients === []) {
                    continue;
                }

                $tUnits = $itemQty * $topping->quantity;
                if ($tUnits <= 0) {
                    continue;
                }

                $tScale = $tUnits / max($this->outputQuantity($tRecipe), 1e-9);
                $this->aggregateIngredients($tIngredients, $tScale, $aggregated);
            }
        }

        // Plan-024 — drop materials whose row is soft-deleted / missing so a
        // stale ingredient reference can't roll back the whole close.
        if ($aggregated !== []) {
            $existingMaterialIds = Material::query()
                ->whereIn('id', array_keys($aggregated))
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            foreach (array_keys($aggregated) as $materialId) {
                if (! in_array($materialId, $existingMaterialIds, true)) {
                    Log::warning('order-close: material deduction skipped: material is soft-deleted or missing', [
                        'order_id' => $order->id,
                        'material_id' => $materialId,
                    ]);
                    unset($aggregated[$materialId]);
                }
            }
        }

        if ($aggregated === []) {
            // Every track_stock SKU was either recipe-less or had an
            // empty ingredient list — warnings already logged above.
            return;
        }

        $materialItems = [];
        foreach ($aggregated as $materialId => $info) {
            $materialItems[] = [
                'material_id' => $materialId,
                'quantity' => $info['qty'],
                'unit' => $info['unit'],
            ];
        }

        $transaction = $this->stockTransactionService->create([
            'type' => 'stock_out',
            'sub_type' => 'sales_material_consumption',
            'warehouse_id' => $warehouseId,
            'reference_type' => 'customer_order',
            'reference_id' => $order->id,
            'organization_id' => $order->organizationId,
            'created_by_id' => $createdById,
            'note' => "Recipe-based material deduction for order {$order->orderCode}".$noteSuffix,
            'items' => $materialItems,
        ]);

        // submit() auto-completes when warehouse.auto_approve_stock_out=true;
        // when the flag is false the transaction lands in `pending` and waits
        // for a manager — documented behaviour per plan-024 Decision 8.
        $submitted = $this->stockTransactionService->submit($transaction);

        // Stash the id so the genealogy step can anchor on it when no Phase 1
        // SKU stock_out exists (every SKU was made_to_order).
        $this->lastMaterialConsumptionTransactionId = (string) $submitted->id;
    }

    /**
     * Fold a recipe's ingredient materials into the shared per-material
     * aggregate (plan-040 M5, moved verbatim from OrderClosingService).
     *
     * @param  array<int, mixed>  $ingredients
     * @param  array<string, array{qty: float, unit: ?string}>  $aggregated
     */
    private function aggregateIngredients(array $ingredients, float $scale, array &$aggregated): void
    {
        foreach ($ingredients as $ing) {
            if (! is_array($ing) || empty($ing['material_id'])) {
                continue;
            }

            $materialId = (string) $ing['material_id'];
            $qty = (float) ($ing['quantity'] ?? $ing['qty'] ?? 0) * $scale;
            if ($qty <= 0) {
                continue;
            }

            $unit = $ing['unit'] ?? null;

            if (isset($aggregated[$materialId])) {
                $aggregated[$materialId]['qty'] += $qty;
            } else {
                $aggregated[$materialId] = ['qty' => $qty, 'unit' => $unit];
            }
        }
    }

    /**
     * Sản lượng của công thức, với quy ước `?: 1` của bản cũ giữ nguyên.
     *
     * `0` (hoặc null trong cột) mang nghĩa "chưa khai", và bản cũ đọc nó là
     * **một đơn vị** chứ không phải chia cho 0. Giá trị này là MẪU SỐ của hệ số
     * nhân nguyên liệu, nên đọc sai nó là trừ sai kho — không phải làm tròn.
     *
     * `recipeIsApproved()` biến mất ở #1731: {@see RecipeSnapshot::isApproved()}
     * đã là chính nó, và bản cũ chỉ tồn tại để gỡ cột enum ra khỏi model.
     */
    private function outputQuantity(RecipeSnapshot $recipe): float
    {
        return $recipe->outputQuantity ?: 1.0;
    }

    /**
     * Best-effort FEFO-preview sales genealogy (plan-022 T8.1 / plan-040 C8),
     * moved verbatim from OrderClosingService — which keeps a delegating
     * wrapper so existing callers and tests are untouched.
     *
     * #1605 — nhận **id** (bản #1666 `…ByOrderId` gộp vào đây). `null` = "mọi
     * dòng chưa void của đơn"; mảng RỖNG là tập rỗng THẬT và phải đi tiếp dưới
     * dạng danh sách rỗng — gộp nó vào nhánh `null` sẽ ghi phả hệ cho toàn bộ
     * đơn lần thứ hai trên cùng một giao dịch kho.
     *
     * @param  list<string>|null  $orderItemIds
     */
    public function recordSalesGenealogy(string $orderId, string $transactionId, ?array $orderItemIds = null): void
    {
        $order = $this->orderContext()->find($orderId);

        if ($order === null) {
            return;
        }

        $items = $orderItemIds === null
            ? null
            : $this->orderLines()->byIds($orderItemIds);

        $this->recordSalesGenealogyFor($order, $transactionId, $items);
    }

    /**
     * Thân thật của {@see recordSalesGenealogy()}, dùng lại từ trong
     * {@see deductItems()} vốn ĐÃ cầm sẵn ảnh chụp đơn — tra lại qua cổng ở đó
     * là một truy vấn thừa cho mỗi lượt trừ kho.
     *
     * #1731 — `$skus` cũng đi vào từ ngoài vì cùng lý do: {@see deductItems()}
     * vừa tra cả lô xong. `null` ⇒ tự tra (đường `recordSalesGenealogy()` công
     * khai, gọi một lần cho mỗi đơn).
     *
     * @param  list<OrderLineStockSnapshot>|null  $items
     * @param  array<string, SkuSnapshot>|null  $skus
     */
    private function recordSalesGenealogyFor(
        OrderStockContext $order,
        string $transactionId,
        ?array $items = null,
        ?array $skus = null,
    ): void {
        $warehouseId = $this->getDefaultWarehouse($order->branchId, $order->organizationId);

        $items ??= $this->orderLines()->activeLinesOfOrder($order->id);
        $skus ??= $this->skuSnapshotsFor($items, $order->organizationId);

        foreach ($items as $item) {
            $recipe = $item->productSkuId === null
                ? null
                : ($skus[$item->productSkuId] ?? null)?->recipe;
            if (! $recipe) {
                continue;
            }

            $itemQty = $item->quantity;
            $outputQty = $this->outputQuantity($recipe);
            $scale = $itemQty / max($outputQty, 1e-9);

            $ingredients = $recipe->ingredients;

            // Edge case: recipe has a material_id but no ingredients (an
            // up-stream batched material sold as-is). Treat the recipe's own
            // material as the single ingredient at output_quantity scale.
            if ($ingredients === [] && $recipe->materialId) {
                $ingredients = [[
                    'material_id' => $recipe->materialId,
                    'quantity' => $outputQty,
                    'unit' => $recipe->outputUnit,
                ]];
            }

            foreach ($ingredients as $ing) {
                if (! is_array($ing) || empty($ing['material_id'])) {
                    continue;
                }

                $materialId = (string) $ing['material_id'];
                $qtyNeeded = (float) ($ing['quantity'] ?? $ing['qty'] ?? 0) * $scale;
                if ($qtyNeeded <= 0) {
                    continue;
                }

                $unit = $ing['unit'] ?? null;

                $allocations = $this->stockTransactionService->previewLotsForConsumption(
                    materialId: $materialId,
                    warehouseId: $warehouseId,
                    qtyNeeded: $qtyNeeded,
                    unit: $unit,
                );

                foreach ($allocations as $alloc) {
                    if ($alloc['material_lot_id'] === null) {
                        // Legacy pre-lot stock — genealogy_links.parent_lot_id
                        // is NOT NULL so we cannot record this consumption as
                        // an edge (plan-022 README §5.1).
                        continue;
                    }
                    $parent = MaterialLot::find($alloc['material_lot_id']);
                    if ($parent === null) {
                        continue;
                    }
                    $this->genealogyLinkService->recordSalesConsumption(
                        parentLot: $parent,
                        customerOrderId: (string) $order->id,
                        qtyConsumed: (float) $alloc['qty'],
                        unit: $unit ?? $parent->unit,
                        transactionId: $transactionId,
                    );
                }
            }
        }
    }
}
