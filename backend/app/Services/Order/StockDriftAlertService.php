<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * #2697 (lỗ 3 của #2694) — `[inventory.stock_drift]` phải TỚI ĐƯỢC một con người.
 *
 * ## Vì sao lớp này tồn tại
 *
 * Đường trừ kho fail-open ở nhiều chỗ, và fail-open là ĐÚNG: một lỗi tồn kho
 * không được cuốn theo khoản tiền khách đã trả. Nhưng cái giá của fail-open là
 * phải có ai đó biết, và cho tới #2697 thứ duy nhất còn lại là một dòng
 * `Log::error` kèm chú thích "logs for ops reconciliation". Đo trên production
 * ngày 12/08: **69 dòng, 0 thông báo** — nó chỉ lộ ra vì có người đi grep log.
 *
 * Ruling chủ dự án (#2694): `Log::error` một mình KHÔNG thoả "mọi lỗi phải tới
 * được nhân viên". Nên dòng log Ở LẠI (alerting của DevOps khớp theo tiền tố
 * `[...]`, xem `MoneyFailOpenLogsAreAlertableTest`) và thông báo là thứ THÊM
 * vào, không phải thứ thay thế.
 *
 * ## Ai được báo
 *
 * `shop-manager` + `org-admin` trong phạm vi CHI NHÁNH — cùng quyết định với
 * `order.status_changed` (#2450), và vì cùng một lý do đo được: Platform cấp
 * vai theo `service_role`, nên ở một doanh nghiệp chỉ có chủ quán thì người duy
 * nhất là `admin` và hỏi mỗi `shop-manager` là không bao giờ báo cho ai. Đây là
 * quyết định của SỰ KIỆN NÀY, không suy ra từ thứ bậc vai.
 *
 * Slug lấy đúng từ vựng `RoleTemplateMatrix::ROLES` (toàn gạch ngang). Một slug
 * sai không ném lỗi — nó phân giải ra 0 người nhận và im lặng mãi mãi, tức tái
 * lập đúng cái lỗ này (#2451/#2456). Rào: `AudienceRoleSlugsExistTest` +
 * khẳng định "số người nhận > 0" trong `StockDriftAlertTest`.
 *
 * ## Vì sao nằm ở `App\Services\Order` chứ không phải `App\Services\Inventory`
 *
 * Cạnh lớp `Ordering → Inventory` vừa được trả xong ở #1731 (`LayerCyclesTest`:
 * SCC 9 → 6). Đặt lớp này bên Inventory rồi cho `OrderClosingService` (Ordering)
 * inject nó là dựng lại đúng cạnh đó để đổi lấy một cái tên thư mục. Sự thật nó
 * cầm là ĐƠN HÀNG (order, chi nhánh, mã đơn), còn Inventory chỉ là nơi lỗi phát
 * sinh.
 */
final class StockDriftAlertService
{
    public const TYPE = 'inventory.stock_drift';

    /** Trừ kho thất bại lúc đóng đơn — tiền đã thu, sổ kho lệch. */
    public const STAGE_ORDER_CLOSE = 'order_close';

    /** `stock:repair-void-compensation` chạy lại bù kho và vẫn thất bại. */
    public const STAGE_VOID_REPAIR = 'void_repair';

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Báo cho người sống rằng sổ kho của đơn này đã lệch.
     *
     * @param  string  $stage  một trong các hằng `STAGE_*` — người đọc cần biết
     *                         drift phát sinh ở đâu để chọn cách sửa
     * @param  string  $error  thông điệp lỗi gốc, cắt ngắn khi nhét vào params
     * @param  string|null  $orderItemId  dòng đơn cụ thể, khi biết
     * @return bool đã gửi được hay chưa — caller ghi lại, KHÔNG ném
     */
    public function raise(CustomerOrder $order, string $stage, string $error, ?string $orderItemId = null): bool
    {
        try {
            $branch = $order->branch;

            if (! $branch instanceof Branch) {
                // Không có chi nhánh thì không có phạm vi nào để giải vai. Ghi
                // lại thay vì nuốt: "không gửi được" và "không cần gửi" là hai
                // chuyện khác nhau, và chính chỗ lẫn hai thứ đó đẻ ra #2694.
                Log::warning('stock-drift-alert: order has no branch — nobody to notify', [
                    'order_id' => $order->getKey(),
                    'stage' => $stage,
                ]);

                return false;
            }

            $brand = $this->brandFor($branch, (string) $order->organization_id);

            if (! $brand instanceof Brand) {
                Log::warning('stock-drift-alert: no brand for organization — nobody to notify', [
                    'order_id' => $order->getKey(),
                    'organization_id' => $order->organization_id,
                    'stage' => $stage,
                ]);

                return false;
            }

            // Khoá idempotency ổn định qua retry: (sự kiện, chặng, chủ thể).
            // Chủ thể là DÒNG khi biết dòng — lệnh repair chạy mỗi đêm và mỗi
            // dòng hỏng là một việc phải sửa riêng.
            $subjectRef = $orderItemId ?? (string) $order->getKey();

            $this->notifications->toRole(
                new NotificationRequest(
                    type: self::TYPE,
                    params: [
                        'order_code' => (string) ($order->order_code ?? $order->getKey()),
                        'shop_name' => (string) ($branch->name ?? ''),
                        'stage' => $stage,
                        'error' => Str::limit($error, 300),
                        'order_id' => (string) $order->getKey(),
                        'order_item_id' => (string) ($orderItemId ?? ''),
                    ],
                    organizationId: (string) $order->organization_id,
                    subject: $order,
                    idempotencyKey: self::TYPE.":{$stage}:{$subjectRef}",
                    // Một sự cố kho hàng loạt (69 lần trong một ngày, như 12/08)
                    // phải là MỘT dòng chuông cho quán, không phải 69.
                    aggregationKey: self::TYPE.':branch:'.$branch->getKey(),
                ),
                role: ['shop-manager', 'org-admin'],
                scopeKey: 'branch_id',
                scopeId: (string) $branch->getKey(),
                brand: $brand,
            );

            return true;
        } catch (\Throwable $e) {
            // Audience rỗng (chưa ai giữ vai ở chi nhánh này) cũng rơi vào đây.
            // Thông báo hỏng không được làm hỏng đường đã gọi nó — đường đó vừa
            // giữ lại một khoản tiền khách đã trả.
            Log::warning('stock-drift-alert: dispatch failed', [
                'order_id' => $order->getKey(),
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function brandFor(Branch $branch, string $organizationId): ?Brand
    {
        if ($branch->brand instanceof Brand) {
            return $branch->brand;
        }

        // Cùng đường tra của `CustomerOrderNotificationObserver`: chi nhánh
        // thường không mang `brand_id`, còn brand thì luôn treo dưới cùng một
        // `console_organization_id`.
        $consoleOrgId = Organization::query()
            ->whereKey($organizationId)
            ->value('console_organization_id');

        return $consoleOrgId === null
            ? null
            : Brand::query()->where('console_organization_id', $consoleOrgId)->first();
    }
}
