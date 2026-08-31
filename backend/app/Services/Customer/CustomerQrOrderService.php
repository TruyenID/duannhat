<?php

namespace App\Services\Customer;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Shop\Contracts\TableOccupancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #962 — lớp này thuộc Ordering, còn `tables` và `customers` thuộc hai
 * module khác. Chữ ký cũ nhận thẳng hai model đó; giờ nhận id và đi qua
 * {@see TableOccupancy} cho phần bàn. Mọi caller đều là controller (Composition),
 * nên đổi chữ ký không đẩy nợ sang chỗ khác.
 */
class CustomerQrOrderService
{
    public function __construct(
        private CustomerOrderService $orderService,
        private TableOccupancy $tables,
    ) {}

    /**
     * Create a new order or add items to the existing active order.
     *
     * #962 — nhận ID KHÁCH, không nhận `App\Models\Customer`. Toàn bộ việc method
     * này từng làm với model đó là đọc `->id`; giữ nguyên model chỉ để lấy một
     * khoá là kéo CustomerEngagement vào Ordering mà không dùng gì của nó.
     *
     * @param  array{items: array, note?: string}  $data
     * @param  string|null  $customerId  khách đã đăng nhập, hoặc null cho khách vãng lai
     */
    public function createOrder(string $tableId, array $data, ?string $customerId = null): CustomerOrder
    {
        $table = $this->tables->snapshotById($tableId);

        if ($table === null) {
            throw (new ModelNotFoundException)->setModel('Table', [$tableId]);
        }

        // If table has an order, check if it's still active (not closed/voided)
        if ($table->currentOrderId !== null) {
            // find() (not findOrFail): a table can point at an order that was
            // hard-deleted out from under it (leaving a dangling
            // current_order_id). findOrFail here would throw ModelNotFound →
            // 404 "No query results for CustomerOrder <id>" on EVERY order
            // attempt, permanently bricking the table for customers. Treat a
            // missing order exactly like a closed/voided one: release the
            // stale reference and fall through to create a fresh order.
            $existingOrder = CustomerOrder::find($table->currentOrderId);

            // `status` is cast to CustomerOrderStatusEnum, so compare by enum value.
            $allowedStatuses = [
                CustomerOrderStatusEnum::Open,
                CustomerOrderStatusEnum::Pending,
                CustomerOrderStatusEnum::Dining,
                CustomerOrderStatusEnum::Checkout,
            ];
            if ($existingOrder !== null && in_array($existingOrder->status, $allowedStatuses, true)) {
                $this->orderService->addItems($existingOrder, ['items' => $data['items']]);

                return CustomerOrder::with([
                    'items.productSku.galleryFirst',
                    'items.productSku.product.galleryFirst',
                    'items.productSku.product.categories',
                ])->findOrFail($existingOrder->id);
            }

            // Order is closed/voided/missing → release table and create new order
            $this->tables->releaseByIds([$table->id]);
        }

        $branch = Branch::find($table->branchId);
        $brandId = $branch?->brand?->id;

        $orderData = [
            'order_type' => 'dine_in',
            'organization_id' => $table->organizationId,
            'branch_id' => $table->branchId,
            'brand_id' => $brandId,
            'table_ids' => [$table->id],
            'customer_id' => $customerId,
            'created_by_id' => null,
            'guest_count' => $data['guest_count'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        $order = $this->orderService->create($orderData);

        // Add items after header creation
        if (! empty($data['items'])) {
            $this->orderService->addItems($order, ['items' => $data['items']]);
            $order = CustomerOrder::with([
                'items.productSku.galleryFirst',
                'items.productSku.product.galleryFirst',
                'items.productSku.product.categories',
            ])->findOrFail($order->id);
        }

        return $order;
    }

    /**
     * Đơn đang mở mà bàn đang giữ. Nhận thẳng `current_order_id` — caller đã
     * cầm bàn trong tay, nên nạp lại nó ở đây chỉ để đọc đúng một cột là phí.
     */
    public function getCurrentOrder(?string $currentOrderId): ?CustomerOrder
    {
        if (! $currentOrderId) {
            return null;
        }

        // Only return order if it's in an active status (open/dining/checkout/paying).
        // If the order has been closed/voided, return null so the frontend shows a clean slate.
        return CustomerOrder::with([
            'items.productSku.galleryFirst',
            'items.productSku.product.galleryFirst',
            'items.productSku.product.categories',
            'items.orderItemToppings.toppingGroupItem.product',
            'items.orderItemToppings.productSku.product',
            'payments.paymentMethod',
        ])
            ->active() // Use the active() scope to filter out closed/voided orders
            ->find($currentOrderId);
    }

    public function findById(string $id): ?CustomerOrder
    {
        return CustomerOrder::with([
            'items.productSku.galleryFirst',
            'items.productSku.product.galleryFirst',
            'items.productSku.product.categories',
            'items.orderItemToppings.toppingGroupItem.product',
            'items.orderItemToppings.productSku.product',
            'payments.paymentMethod',
        ])->find($id);
    }
}
