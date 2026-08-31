<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Order\Contracts\OrderLineStockSnapshot;
use App\Services\Order\Contracts\OrderLineToppingStockSnapshot;
use App\Services\Order\Contracts\OrderStockLineReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #1731 — hiện thực {@see OrderStockLineReads}.
 *
 * Mỗi câu truy vấn chép NGUYÊN từ chỗ nó vừa rời khỏi
 * (`App\Services\Inventory\StockDeductionService`), kể cả những chỗ trông như
 * thiếu bộ lọc:
 *
 *  - {@see activeLinesOfOrder} **không** lọc `refund_of_item_id` — bản cũ của
 *    đường phả hệ cũng không. Thêm vào cho "nhất quán" là làm mất cạnh phả hệ
 *    của những dòng bản cũ vẫn ghi.
 *  - `where('status', '!=', 'voided')` so chuỗi thô, không so enum — cột là
 *    `string` và bản cũ so y hệt.
 *
 * ## `select` hẹp, và topping nạp bằng MỘT truy vấn gộp
 *
 * Bản cũ eager-load `productSku.recipe` + `orderItemToppings.productSku.recipe`
 * (≈6 truy vấn). Ở đây chỉ còn **dòng + topping = 2**: phần danh mục
 * (`inventory_mode` + công thức) do Catalog trả qua `SkuDirectory`, một lượt
 * `whereIn` cho cả lô. Tức cạnh ranh giới được gỡ mà số truy vấn **giảm**, không
 * phải đánh đổi.
 */
final class EloquentOrderStockLineReads implements OrderStockLineReads
{
    /** Cột duy nhất mà {@see OrderLineStockSnapshot} cần. */
    private const COLUMNS = [
        'id',
        'customer_order_id',
        'product_sku_id',
        'quantity',
        'unit_price',
        'stock_deducted_at',
        'stock_out_transaction_id',
    ];

    public function orderIdOf(string $orderItemId): ?string
    {
        $orderId = CustomerOrderItem::query()->whereKey($orderItemId)->value('customer_order_id');

        return $orderId === null ? null : (string) $orderId;
    }

    public function find(string $orderItemId): ?OrderLineStockSnapshot
    {
        return $this->first(CustomerOrderItem::query()->whereKey($orderItemId));
    }

    public function lockLine(string $orderItemId): ?OrderLineStockSnapshot
    {
        $this->assertInTransaction(__FUNCTION__);

        return $this->first(CustomerOrderItem::query()->whereKey($orderItemId)->lockForUpdate());
    }

    public function lockUndeductedLine(string $orderItemId): ?OrderLineStockSnapshot
    {
        $this->assertInTransaction(__FUNCTION__);

        return $this->first(
            CustomerOrderItem::query()
                ->whereKey($orderItemId)
                ->whereNull('stock_deducted_at')
                ->where('status', '!=', OrderItemStatusEnum::Voided->value)
                ->whereNull('refund_of_item_id')
                ->lockForUpdate()
        );
    }

    public function undeductedLinesOfOrder(string $orderId): array
    {
        return $this->many(
            CustomerOrderItem::query()
                ->where('customer_order_id', $orderId)
                ->where('status', '!=', 'voided')
                ->whereNull('refund_of_item_id')
                ->whereNull('stock_deducted_at')
        );
    }

    public function activeLinesOfOrder(string $orderId): array
    {
        return $this->many(
            CustomerOrderItem::query()
                ->where('customer_order_id', $orderId)
                ->where('status', '!=', 'voided')
        );
    }

    public function byIds(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        return $this->many(CustomerOrderItem::query()->whereIn('id', $orderItemIds));
    }

    /**
     * `SELECT … FOR UPDATE` ngoài transaction chạy xong là nhả khoá — không lỗi,
     * và **không khoá gì cả**. Ném ở đây thay vì tự mở transaction: biên giao
     * dịch là của chỗ gọi (nó còn ghi phiếu kho trong cùng biên đó), nên mở một
     * cái riêng ở đây sẽ khoá đúng khoảng thời gian không có việc gì xảy ra.
     */
    private function assertInTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(sprintf(
                '%s::%s() phải được gọi bên trong một transaction — ngoài transaction thì khoá được nhả ngay và không chặn được trừ kho hai lần.',
                self::class,
                $method,
            ));
        }
    }

    /**
     * @param  Builder<CustomerOrderItem>  $query
     */
    private function first(Builder $query): ?OrderLineStockSnapshot
    {
        $row = $query->first(self::COLUMNS);

        if ($row === null) {
            return null;
        }

        return $this->snapshot($row, $this->toppingsFor([(string) $row->getKey()]));
    }

    /**
     * @param  Builder<CustomerOrderItem>  $query
     * @return list<OrderLineStockSnapshot>
     */
    private function many(Builder $query): array
    {
        $rows = $query->get(self::COLUMNS);

        if ($rows->isEmpty()) {
            return [];
        }

        $toppings = $this->toppingsFor($rows->map(fn ($r) => (string) $r->getKey())->all());

        return $rows->map(fn (CustomerOrderItem $row) => $this->snapshot($row, $toppings))->values()->all();
    }

    /**
     * @param  list<string>  $lineIds
     * @return array<string, list<OrderLineToppingStockSnapshot>>
     */
    private function toppingsFor(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        $out = [];
        $rows = OrderItemTopping::query()
            ->whereIn('customer_order_item_id', $lineIds)
            ->get(['customer_order_item_id', 'product_sku_id', 'quantity', 'status']);

        foreach ($rows as $row) {
            $status = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;

            $out[(string) $row->customer_order_item_id][] = new OrderLineToppingStockSnapshot(
                productSkuId: $row->product_sku_id === null ? null : (string) $row->product_sku_id,
                quantity: (float) ($row->quantity ?? 0),
                voided: $status === OrderItemStatusEnum::Voided->value,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, list<OrderLineToppingStockSnapshot>>  $toppings
     */
    private function snapshot(CustomerOrderItem $row, array $toppings): OrderLineStockSnapshot
    {
        $deductedAt = $row->stock_deducted_at;

        return new OrderLineStockSnapshot(
            id: (string) $row->getKey(),
            orderId: (string) $row->customer_order_id,
            productSkuId: $row->product_sku_id === null ? null : (string) $row->product_sku_id,
            quantity: (float) ($row->quantity ?? 0),
            unitPrice: $row->unit_price === null ? null : (float) $row->unit_price,
            stockDeductedAt: $deductedAt === null
                ? null
                : \DateTimeImmutable::createFromInterface($deductedAt),
            stockOutTransactionId: $row->stock_out_transaction_id === null
                ? null
                : (string) $row->stock_out_transaction_id,
            toppings: $toppings[(string) $row->getKey()] ?? [],
        );
    }
}
